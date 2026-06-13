// Script pour créer/mettre à jour un compte de test avec mot de passe simple
const bcrypt = require('bcryptjs');
const fs = require('fs');
const path = require('path');

async function createTestAccount() {
  const usersPath = path.join(__dirname, 'data', 'users.json');
  
  // Lire la DB
  let db = JSON.parse(fs.readFileSync(usersPath, 'utf8'));
  
  // Hash le mot de passe test
  const testPassword = 'Test1234!@#'; // Un mot de passe valide (12+ chars, maj, digit, special)
  const passwordHash = await bcrypt.hash(testPassword, 12);
  
  // Chercher ou créer le compte test
  let testUser = db.users.find(u => u.username === 'test_user');
  
  if (!testUser) {
    testUser = {
      id: '12345678-1234-1234-1234-123456789012',
      username: 'test_user',
      passwordHash,
      role: 'user',
      createdAt: new Date().toISOString(),
      failedLoginAttempts: 0,
      lockedUntil: null,
      lastLogin: null,
      balance: 100,
      totalSpent: 0,
      plan: 'starter',
      planExpiresAt: new Date(Date.now() + 30 * 86400000).toISOString(),
      planStartedAt: new Date().toISOString(),
      transactions: [],
      domains: [],
      pages: [],
      cloudflareToken: null,
      pendingPayments: []
    };
    db.users.push(testUser);
  } else {
    testUser.passwordHash = passwordHash;
    testUser.balance = 100;
  }
  
  // Sauvegarder
  fs.writeFileSync(usersPath, JSON.stringify(db, null, 2));
  
  console.log('✅ Compte de test créé/mis à jour');
  console.log('Identifiant: test_user');
  console.log(`Mot de passe: ${testPassword}`);
  console.log('✅ Vous pouvez maintenant vous connecter avec ces identifiants');
}

createTestAccount().catch(err => {
  console.error('❌ Erreur:', err.message);
  process.exit(1);
});
