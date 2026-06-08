'use strict';

const crypto = require('crypto');

const DAYS_FR = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];

function pad(n) { return String(n).padStart(2, '0'); }
function formatDate(date = new Date()) { return `${pad(date.getDate())}/${pad(date.getMonth() + 1)}/${date.getFullYear()}`; }
function formatTime(date = new Date()) { return `${pad(date.getHours())}:${pad(date.getMinutes())}`; }
function capitalize(v) { v = String(v || ''); return v ? v[0].toUpperCase() + v.slice(1).toLowerCase() : ''; }
function formatPhone(v) {
  const s = String(v || '');
  if (!s.startsWith('+33')) return s;
  const d = s.replace(/\D/g, '');
  return `+33 ${d.slice(2, 3)} ${d.slice(3, 5)} ${d.slice(5, 7)} ${d.slice(7, 9)} ${d.slice(9, 11)}`.trim();
}

class MessageVariableEngine {
  buildContext(contact = {}) {
    const date = new Date();
    const vars = { ...(contact.variables || {}) };
    vars.phone = contact.phone || vars.phone || '';
    vars.country = contact.country || vars.country || '';
    vars.date = formatDate(date);
    vars.time = formatTime(date);
    vars.datetime = `${vars.date} ${vars.time}`;
    vars.day = DAYS_FR[date.getDay()];
    vars.random = String(Math.floor(Math.random() * 10000)).padStart(4, '0');
    vars.uuid = crypto.randomUUID();
    return vars;
  }

  applyFunction(value, fn = '') {
    const [name] = String(fn || '').split(':');
    switch (name) {
      case 'capitalize': return capitalize(value);
      case 'uppercase': return String(value || '').toUpperCase();
      case 'lowercase': return String(value || '').toLowerCase();
      case 'format': return formatPhone(value);
      default: return String(value ?? '');
    }
  }

  render(template, contact = {}) {
    const ctx = this.buildContext(contact);
    const resolve = (expr) => {
      const [key, fn] = String(expr || '').split('|');
      const value = ctx[String(key || '').trim()] ?? '';
      return fn ? this.applyFunction(value, fn.trim()) : String(value ?? '');
    };
    return String(template || '')
      .replace(/\{\{\s*([^{}]+?)\s*\}\}/g, (_m, expr) => resolve(expr))
      .replace(/\{\s*([^{}]+?)\s*\}/g, (_m, expr) => resolve(expr));
  }

  detectVariables(template) {
    const vars = new Set();
    String(template || '').replace(/\{\{?\s*([^{}|]+)(?:\|[^{}]+)?\s*\}?\}/g, (_m, key) => {
      vars.add(String(key).trim());
      return _m;
    });
    return [...vars];
  }
}

module.exports = MessageVariableEngine;