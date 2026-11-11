-- Área /user — utilizadores com login (separado do painel /admin).
-- Aplicar na mesma base do CMS (ex.: elbasoftcom_fazendoemcasa):
--   mysql -u ... -p NOME_DA_BASE < scripts/vps/03-member-users.sql

CREATE TABLE IF NOT EXISTS member_users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(120) NOT NULL,
  login VARCHAR(80) NOT NULL,
  email VARCHAR(255) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  valor_pago DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
  plano_valido_desde DATE NULL,
  plano_valido_ate DATE NOT NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_member_login (login),
  UNIQUE KEY uq_member_email (email),
  INDEX idx_member_plano_ate (plano_valido_ate),
  INDEX idx_member_ativo (ativo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
