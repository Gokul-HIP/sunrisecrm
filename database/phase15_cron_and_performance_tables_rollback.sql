-- Phase 15 Rollback: Drop Cron Run Log, Request Load Minute, and Slow Queries tables

DROP TABLE IF EXISTS `rise_slow_queries`;
DROP TABLE IF EXISTS `rise_request_load_minute`;
DROP TABLE IF EXISTS `rise_cron_run_log`;
