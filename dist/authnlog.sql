CREATE TABLE IF NOT EXISTS stepup
(
  id BINARY(16) PRIMARY KEY NOT NULL,
  ts DATETIME NOT NULL,
  idp_enityid VARCHAR(256) NOT NULL,
  sp_enitytid VARCHAR(256) NOT NULL,
  nameid VARCHAR(256) NOT NULL,
  institution VARCHAR(60),
  sndf_id VARCHAR(36),
  sndf_type VARCHAR(16),
  result VARCHAR(8),
  loa VARCHAR(45) NOT NULL,
  request_id VARCHAR(45) NOT NULL
);
ALTER TABLE stepup
  CREATE UNIQUE INDEX IF NOT EXISTS id_UNIQUE ON stepup (id),
  CREATE INDEX IF NOT EXISTS timestamp_index ON stepup (ts),
  CREATE INDEX IF NOT EXISTS nameid ON stepup (nameid),
  CREATE INDEX IF NOT EXISTS idp_enityid_index ON stepup (idp_enityid),
  CREATE INDEX IF NOT EXISTS sp_enitytid_index ON stepup (sp_enitytid);

