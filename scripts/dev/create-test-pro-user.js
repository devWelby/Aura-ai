#!/usr/bin/env node
require('dotenv').config();
const mysql = require('mysql2/promise');
const bcrypt = require('bcryptjs');

async function main() {
  const email = process.argv[2] || 'teste.pro@aura-ai.local';
  const senha = process.argv[3] || 'AuraPro2026!';

  const pool = mysql.createPool({
    host: process.env.DB_HOST || 'localhost',
    user: process.env.DB_USER || 'root',
    password: process.env.DB_PASS || '',
    database: process.env.DB_NAME || 'analista_financeiro_db',
    port: Number(process.env.DB_PORT || 3306),
    waitForConnections: true,
    connectionLimit: 2,
  });

  const hash = await bcrypt.hash(senha, 10);
  const [rows] = await pool.execute('SELECT id FROM usuarios WHERE email = ?', [email]);

  if (rows.length > 0) {
    await pool.execute(
      "UPDATE usuarios SET nome = ?, senha = ?, plano = 'pro', email_verificado = 1, token_verificacao = NULL WHERE id = ?",
      ['Usuario Teste Pro', hash, Number(rows[0].id)],
    );
    console.log('USER_UPDATED');
  } else {
    await pool.execute(
      "INSERT INTO usuarios (nome, email, senha, plano, email_verificado, token_verificacao) VALUES (?, ?, ?, 'pro', 1, NULL)",
      ['Usuario Teste Pro', email, hash],
    );
    console.log('USER_CREATED');
  }

  console.log(`EMAIL=${email}`);
  console.log(`SENHA=${senha}`);

  await pool.end();
}

main().catch((err) => {
  console.error(err.message || err);
  process.exit(1);
});
