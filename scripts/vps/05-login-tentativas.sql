-- Rate-limit de login no painel admin (aplicar na base de produção uma vez).
USE elbasoftcom_fazendoemcasa;

CREATE TABLE IF NOT EXISTS login_tentativas (
  ip VARCHAR(45) NOT NULL PRIMARY KEY,
  tentativas SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  primeiro_ts TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ultimo_ts TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_login_ultimo (ultimo_ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
