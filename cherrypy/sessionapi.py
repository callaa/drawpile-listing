import cherrypy
import random
import string
import settings
import re
import json
import datetime

SESSION_ID_RE = re.compile(r'^[a-zA-Z0-9:-]{1,64}$')


class SessionListingApi(object):

    """API root object"""
    exposed = True

    def __init__(self):
        self.sessions = Sessions()

    def GET(self):
        return {
            'api_name': 'drawpile-session-list',
            'version': '1.1',
            'name': settings.NAME,
            'description': settings.DESCRIPTION,
            'favicon': settings.FAVICON,
        }


class Sessions(object):

    """Session listing API"""
    exposed = True

    def GET(self, protocol=None, nsfm="false", **kwargs):
        """Get list of active sessions"""

        sql = '''
			SELECT host, port, session_id as id, protocol, owner, title, users, password, nsfm, started
			FROM drawpile_sessions
			WHERE unlisted=false AND last_active >= current_timestamp - interval %s'''
        params = [settings.SESSION_TIMEOUT]

        if protocol:
            sql += " AND protocol=%s"
            params.append(protocol)

        if nsfm.lower() != 'true' and nsfm != '':
            sql += " AND nsfm=false"

        sql += " ORDER BY title ASC"

        with settings.db() as conn:
            with conn.cursor() as cur:
                cur.execute(sql, params)

                columns = tuple(x[0] for x in cur.description)
                sessions = [Session(**dict(zip(columns, row))) for row in cur]

        return [s.to_json() for s in sessions]

    def POST(self):
        """Announce a new session"""
        data = cherrypy.request.json

        # Note: X-Real-Ip is used with nginx proxying
        remoteip = cherrypy.request.headers.get('x-real-ip', cherrypy.request.remote.ip)

        pk, updatekey = Session.create(data, remoteip)

        return {
            'id': pk,
            'key': updatekey,
        }

    def _cp_dispatch(self, vpath):
        if len(vpath) == 1 and vpath[0].isdigit():
            session = Session(pk=int(vpath[0]))
            vpath.pop(0)
            return session

        return None


class Session(object):

    """API for updating individual sessions"""

    exposed = True

    # Public attributes
    ATTRS = ('host', 'port', 'id', 'protocol', 'title',
             'users', 'password', 'nsfm', 'owner', 'started')

    def __init__(self, **kwargs):
        for k, v in kwargs.iteritems():
            setattr(self, k, v)

    def PUT(self):
        """Refresh this announcement"""
        data = cherrypy.request.json
        with settings.db() as conn:
            with conn.cursor() as cur:
                self._check_update_key(cur)

                set_sql = ['last_active = current_timestamp']
                params = []
                if data.get('title', None):
                    set_sql.append('title=%s')
                    params.append(data['title'])

                if data.get('users', None):
                    set_sql.append('users=%s')
                    params.append(data['users'])

                if data.get('password', None):
                    set_sql.append('password=%s')
                    params.append(data['password'])

                if data.get('owner', None):
                    set_sql.append('owner=%s')
                    params.append(data['owner'])

                if data.get('nsfm', 'false').lower() != 'false' or is_nsfm_title(data.get('title', '')):
                    set_sql.append('nsfm=true')

                sql = 'UPDATE drawpile_sessions SET ' +\
                    ', '.join(set_sql) +\
                    ' WHERE id=%s'
                params.append(self.pk)

                cur.execute(sql, params)

                return {'status': 'ok'}

    def DELETE(self):
        """Unlist this announcement"""
        with settings.db() as conn:
            with conn.cursor() as cur:
                self._check_update_key(cur)

                cur.execute(
                    'UPDATE drawpile_sessions SET unlisted=true WHERE id=%s', [self.pk])

                cherrypy.response.status = 204

    def _check_update_key(self, cursor):
        """Check if the given update key is valid for the listing"""
        cursor.execute(
            """SELECT update_key FROM drawpile_sessions
			WHERE id=%s
			AND unlisted=false
			AND last_active >= current_timestamp - interval %s""",
            [self.pk, settings.SESSION_TIMEOUT]
        )
        row = cursor.fetchone()
        if not row:
            raise cherrypy.HTTPError(404)
        elif row[0] != cherrypy.request.headers.get('X-Update-Key'):
            raise cherrypy.HTTPError(403)

    @classmethod
    def create(cls, data, client_ip):
        # Validate data
        if not SESSION_ID_RE.match(data['id']):
            raise cherrypy.HTTPError(422, "BADDATA:Invalid ID")

        host = data.get('host', client_ip)

        # TODO validate host name

        if 'port' not in data:
            port = 27750
        else:
            if not data['port'].isdigit():
                raise cherrypy.HTTPError(422, "BADDATA:Invalid port number")
            port = int(data['port'])
            if port <= 0 or port >= 65536:
                raise cherrypy.HTTPError(422, "BADDATA:Invalid port number")

        sql = '''INSERT INTO drawpile_sessions
			(host, port, session_id, protocol, owner, title, users, password, nsfm, update_key, client_ip) 
			VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s) RETURNING id'''

        with settings.db() as conn:
            with conn.cursor() as cur:
                # Rate limiting
                cur.execute('''SELECT COUNT(id) FROM drawpile_sessions
					WHERE client_ip=%s AND last_active >= current_timestamp - interval %s''',
                            [client_ip, settings.SESSION_TIMEOUT]
                            )
                count = cur.fetchone()[0]
                if count > settings.RATELIMIT:
                    raise cherrypy.HTTPError(
                        429, "You have announced too many sessions ({0}) too quickly!".format(count))

                # Check for duplicates
                cur.execute('''SELECT COUNT(id) FROM drawpile_sessions
					WHERE host=%s AND port=%s AND session_id=%s AND unlisted=false AND last_active >= current_timestamp - interval %s''',
                            [host, port, data['id'], settings.SESSION_TIMEOUT]
                            )
                count = cur.fetchone()[0]
                if count > 0:
                    raise cherrypy.HTTPError(
                        422, "DUPLICATE:session already listed")

                # OK: insert entry
                update_key = ''.join(random.SystemRandom().choice(
                    string.ascii_uppercase + string.digits) for _ in range(16))
                try:
                    cur.execute(sql, (
                        host,
                        port,
                        data['id'],
                        data['protocol'],
                        data['owner'],
                        data.get('title', ''),
                        data['users'],
                        data.get('password', False),
                        data.get('nsfm', False) or is_nsfm_title(data.get('title', '')),
                        update_key,
                        client_ip
                    ))
                except KeyError as ke:
                    raise cherrypy.HTTPError(422, "BADDATA:" + str(ke))

                pk = cur.fetchone()[0]

                return (pk, update_key)

    def to_json(self):
        json = {a: getattr(self, a) for a in self.ATTRS}

        s = json['started'].utctimetuple()
        json['started'] = '{:#04}-{:#02}-{:#02} {:#02}:{:#02}:{:#02}'.format(*s[0:6])

        return json


def error422(status, message, traceback, version):
    code, msg = message.split(':', 1)
    return json.dumps({
        'error': code,
        'message': msg
    })

def is_nsfm_title(title):
    title = title.lower()
    for word in settings.NSFM_WORDS:
        if hasattr(word, 'search'):
            if word.search(title):
                return True

        elif word in title:
                return True

    return False

if __name__ == '__main__':
    conf = {
        '/': {
            'request.dispatch': cherrypy.dispatch.MethodDispatcher(),
            'tools.json_in.on': True,
            'tools.json_out.on': True,
            'error_page.422': error422,
            'request.show_tracebacks': False,
        }
    }
    cherrypy.quickstart(SessionListingApi(), '/', conf)

