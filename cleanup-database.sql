DELETE FROM drawpile_sessions WHERE unlisted!=0 OR last_active < TIMESTAMPADD(MINUTE, -120, CURRENT_TIMESTAMP);
