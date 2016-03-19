import cherrypy
import json

def jsonp_handler(*args, **kwargs):
    callback = cherrypy.serving.request.params.pop('callback', None)
    value = cherrypy.serving.request._json_inner_handler(*args, **kwargs)
    jv = json.dumps(value)

    if callback:
        jv = u'%s(%s)' % (callback, jv)

    return jv

