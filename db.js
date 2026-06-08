/**
 * Dontplay — Couche d'abstraction base de données
 *
 * Actuellement : JSON file (développement local)
 * Future compatibilité : MySQL/MariaDB via mysql2
 *
 * L'interface publique est identique dans les deux cas :
 *   db.findUserByUsername(username)
 *   db.findUserByEmail(email)
 *   db.findUserById(id)
 *   db.createUser({ username, email, passwordHash, role })
 *   db.updateUser(id, updates)
 *   db.countUsers()
 *   db.getAllUsers()
 */

'use strict';

const fs     = require('fs');
const path   = require('path');
const crypto = require('crypto');
const config = require('./config');

/* ── Utilitaires ──────────────────────────────────────────────── */
function newId() {
  return crypto.randomUUID();
}

/* ═══════════════════════════════════════════════════════════════
   JSON FILE DATABASE
   Adapté pour le développement local — aucune dépendance externe
   ═══════════════════════════════════════════════════════════════ */
class JsonDB {
  constructor(filePath) {
    this.filePath = path.resolve(filePath);
    this._lockQueue = Promise.resolve(); // serialise toutes les écritures
    this._ensureFile();
  }

  // Exécute fn en exclusion mutuelle (une seule opération à la fois)
  _withLock(fn) {
    const next = this._lockQueue.then(() => fn());
    this._lockQueue = next.catch(() => {});
    return next;
  }

  _ensureFile() {
    const dir = path.dirname(this.filePath);
    if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
    if (!fs.existsSync(this.filePath)) {
      fs.writeFileSync(this.filePath, JSON.stringify({ users: [] }, null, 2), 'utf8');
    }
  }

  _read() {
    try {
      return JSON.parse(fs.readFileSync(this.filePath, 'utf8'));
    } catch {
      return { users: [] };
    }
  }

  _write(data) {
    // Écriture atomique : fichier temporaire puis rename
    const tmp = this.filePath + '.tmp';
    fs.writeFileSync(tmp, JSON.stringify(data, null, 2), 'utf8');
    fs.renameSync(tmp, this.filePath);
  }

  async findUserByUsername(username) {
    const { users } = this._read();
    return users.find(u => u.username.toLowerCase() === username.toLowerCase()) || null;
  }

  async findUserByEmail(email) {
    if (!email) return null;
    const { users } = this._read();
    return users.find(u => u.email && u.email.toLowerCase() === email.toLowerCase()) || null;
  }

  async findUserById(id) {
    const { users } = this._read();
    return users.find(u => u.id === id) || null;
  }

  async createUser({ username, passwordHash, role = 'user' }) {
    return this._withLock(() => {
      const data = this._read();
      const user = {
        id:                  newId(),
        username:            username.trim(),
        passwordHash,
        role,
        createdAt:           new Date().toISOString(),
        failedLoginAttempts: 0,
        lockedUntil:         null,
        lastLogin:           null,
        balance:             0,
        totalSpent:          0,
        plan:                null,
        planExpiresAt:       null,
        planStartedAt:       null,
        transactions:        [],
        domains:             [],
        pages:               [],
        campaigns:           [],
        cloudflareToken:     null,
        pendingPayments:     [],
      };
      data.users.push(user);
      this._write(data);
      return user;
    });
  }

  async updateUser(id, updates) {
    return this._withLock(() => {
      const data = this._read();
      const idx  = data.users.findIndex(u => u.id === id);
      if (idx === -1) throw new Error('Utilisateur introuvable.');
      data.users[idx] = { ...data.users[idx], ...updates };
      this._write(data);
      return data.users[idx];
    });
  }

  /**
   * Lit l'utilisateur, appelle fn(user) → updates (ou throw pour annuler),
   * puis écrit — tout en exclusion mutuelle.
   * Évite les race conditions sur balance, pages, domaines, etc.
   */
  async atomicUpdate(id, fn) {
    return this._withLock(() => {
      const data = this._read();
      const idx  = data.users.findIndex(u => u.id === id);
      if (idx === -1) throw new Error('Utilisateur introuvable.');
      const updates = fn(data.users[idx]); // throws to abort
      data.users[idx] = { ...data.users[idx], ...updates };
      this._write(data);
      return data.users[idx];
    });
  }

  async countUsers() {
    return this._read().users.length;
  }

  async getAllUsers() {
    return this._read().users.map(u => {
      const { passwordHash, ...safe } = u;
      return safe;
    });
  }

  async deleteUser(id) {
    return this._withLock(() => {
      const data = this._read();
      const idx  = data.users.findIndex(u => u.id === id);
      if (idx === -1) throw new Error('Utilisateur introuvable.');
      data.users.splice(idx, 1);
      this._write(data);
    });
  }
}

/* ═══════════════════════════════════════════════════════════════
   MYSQL DATABASE
   ═══════════════════════════════════════════════════════════════ */
class MysqlDB {
  constructor(cfg) {
    const mysql = require('mysql2/promise');
    this.pool = mysql.createPool({
      host:               cfg.host,
      port:               cfg.port,
      user:               cfg.user,
      password:           cfg.password,
      database:           cfg.database,
      waitForConnections: true,
      connectionLimit:    10,
      timezone:           'Z',        // stocke en UTC
      decimalNumbers:     true,       // balance/totalSpent → number
    });
  }

  /* ── Sérialisation ───────────────────────────────────────── */
  _fromRow(row) {
    if (!row) return null;
    return {
      ...row,
      createdAt:    row.createdAt    ? row.createdAt.toISOString()    : null,
      lockedUntil:  row.lockedUntil  ? row.lockedUntil.toISOString()  : null,
      lastLogin:    row.lastLogin    ? row.lastLogin.toISOString()    : null,
      planExpiresAt:row.planExpiresAt? row.planExpiresAt.toISOString(): null,
      planStartedAt:row.planStartedAt? row.planStartedAt.toISOString(): null,
      transactions: typeof row.transactions === 'string' ? JSON.parse(row.transactions) : (row.transactions ?? []),
      domains:      typeof row.domains      === 'string' ? JSON.parse(row.domains)      : (row.domains      ?? []),
      pages:        typeof row.pages        === 'string' ? JSON.parse(row.pages)        : (row.pages        ?? []),
      campaigns:    typeof row.campaigns    === 'string' ? JSON.parse(row.campaigns)    : (row.campaigns    ?? []),
    };
  }

  _isoOrNull(v) { return v ? new Date(v) : null; }

  /* ── Lecture ─────────────────────────────────────────────── */
  async findUserByUsername(username) {
    const [rows] = await this.pool.execute(
      'SELECT * FROM users WHERE LOWER(username) = LOWER(?) LIMIT 1', [username]
    );
    return this._fromRow(rows[0] ?? null);
  }

  async findUserByEmail(email) {
    // Champ email absent du schéma — retourne null
    return null;
  }

  async findUserById(id) {
    const [rows] = await this.pool.execute(
      'SELECT * FROM users WHERE id = ? LIMIT 1', [id]
    );
    return this._fromRow(rows[0] ?? null);
  }

  /* ── Écriture ────────────────────────────────────────────── */
  async createUser({ username, passwordHash, role = 'user' }) {
    const id = crypto.randomUUID();
    const now = new Date();
    await this.pool.execute(
      `INSERT INTO users
         (id, username, passwordHash, role, createdAt,
          failedLoginAttempts, balance, totalSpent, transactions, domains, pages, campaigns)
       VALUES (?, ?, ?, ?, ?, 0, 0, 0, '[]', '[]', '[]', '[]')`,
      [id, username.trim(), passwordHash, role, now]
    );
    return this.findUserById(id);
  }

  async updateUser(id, updates) {
    const allowed = [
      'username','passwordHash','role','failedLoginAttempts','lockedUntil',
      'lastLogin','balance','totalSpent','plan','planExpiresAt','planStartedAt',
      'cloudflareToken','transactions','domains','pages','campaigns',
    ];
    const fields = [];
    const values = [];
    for (const [k, v] of Object.entries(updates)) {
      if (!allowed.includes(k)) continue;
      fields.push(`\`${k}\` = ?`);
      // Dates
      if (['lockedUntil','lastLogin','planExpiresAt','planStartedAt'].includes(k)) {
        values.push(this._isoOrNull(v));
      // JSON arrays
      } else if (['transactions','domains','pages','campaigns'].includes(k)) {
        values.push(JSON.stringify(v ?? []));
      } else {
        values.push(v ?? null);
      }
    }
    if (!fields.length) return this.findUserById(id);
    values.push(id);
    await this.pool.execute(
      `UPDATE users SET ${fields.join(', ')} WHERE id = ?`, values
    );
    return this.findUserById(id);
  }

  /**
   * Lecture + fn(user) + écriture en transaction InnoDB (SELECT FOR UPDATE).
   * Même interface que JsonDB.atomicUpdate.
   */
  async atomicUpdate(id, fn) {
    const conn = await this.pool.getConnection();
    try {
      await conn.beginTransaction();
      const [rows] = await conn.execute(
        'SELECT * FROM users WHERE id = ? LIMIT 1 FOR UPDATE', [id]
      );
      if (!rows[0]) throw new Error('Utilisateur introuvable.');
      const user    = this._fromRow(rows[0]);
      const updates = fn(user); // throws to abort

      const allowed = [
        'username','passwordHash','role','failedLoginAttempts','lockedUntil',
        'lastLogin','balance','totalSpent','plan','planExpiresAt','planStartedAt',
        'cloudflareToken','transactions','domains','pages',
      ];
      const fields = [];
      const values = [];
      for (const [k, v] of Object.entries(updates)) {
        if (!allowed.includes(k)) continue;
        fields.push(`\`${k}\` = ?`);
        if (['lockedUntil','lastLogin','planExpiresAt','planStartedAt'].includes(k)) {
          values.push(this._isoOrNull(v));
        } else if (['transactions','domains','pages'].includes(k)) {
          values.push(JSON.stringify(v ?? []));
        } else {
          values.push(v ?? null);
        }
      }
      if (fields.length) {
        values.push(id);
        await conn.execute(`UPDATE users SET ${fields.join(', ')} WHERE id = ?`, values);
      }
      await conn.commit();
      return this.findUserById(id);
    } catch (err) {
      await conn.rollback();
      throw err;
    } finally {
      conn.release();
    }
  }

  async countUsers() {
    const [rows] = await this.pool.execute('SELECT COUNT(*) AS n FROM users');
    return rows[0].n;
  }

  async getAllUsers() {
    const [rows] = await this.pool.execute('SELECT * FROM users');
    return rows.map(r => {
      const u = this._fromRow(r);
      const { passwordHash, ...safe } = u;
      return safe;
    });
  }

  async deleteUser(id) {
    const [result] = await this.pool.execute('DELETE FROM users WHERE id = ?', [id]);
    if (result.affectedRows === 0) throw new Error('Utilisateur introuvable.');
  }
}

/* ── Factory ───────────────────────────────────────────────────── */
function createDB() {
  switch (config.db.type) {
    case 'json':  return new JsonDB(config.db.json.path);
    case 'mysql': return new MysqlDB(config.db.mysql);
    default: throw new Error(`[DB] Type inconnu : "${config.db.type}"`);
  }
}

module.exports = createDB();
