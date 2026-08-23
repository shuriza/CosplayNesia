const { getDb } = require('../db/database');
const bcrypt = require('bcryptjs');
const jwt = require('jsonwebtoken');

const JWT_SECRET = process.env.JWT_SECRET || 'super-secret-cosplaynesia-key';

module.exports = async (req, res) => {
  // Enable CORS
  res.setHeader("Access-Control-Allow-Credentials", true);
  res.setHeader("Access-Control-Allow-Origin", "*");
  res.setHeader("Access-Control-Allow-Methods", "POST,OPTIONS");
  res.setHeader("Access-Control-Allow-Headers", "Content-Type, Authorization");

  if (req.method === "OPTIONS") {
    res.status(200).end();
    return;
  }

  if (req.method !== 'POST') {
    return res.status(405).json({ error: 'Method not allowed' });
  }

  const { action, email, password } = req.body;

  if (!email || !password) {
    return res.status(400).json({ error: 'Email and password required' });
  }

  try {
    const db = await getDb();

    if (action === 'register') {
      const existingUser = await db.get('SELECT * FROM users WHERE email = ?', email);
      if (existingUser) {
        return res.status(400).json({ error: 'Email already registered' });
      }

      const hashedPassword = await bcrypt.hash(password, 10);
      const result = await db.run('INSERT INTO users (email, password) VALUES (?, ?)', email, hashedPassword);
      
      const token = jwt.sign({ id: result.lastID, email }, JWT_SECRET, { expiresIn: '7d' });
      return res.status(200).json({ token, user: { id: result.lastID, email } });

    } else if (action === 'login') {
      const user = await db.get('SELECT * FROM users WHERE email = ?', email);
      if (!user) {
        return res.status(400).json({ error: 'Invalid email or password' });
      }

      const isValid = await bcrypt.compare(password, user.password);
      if (!isValid) {
        return res.status(400).json({ error: 'Invalid email or password' });
      }

      const token = jwt.sign({ id: user.id, email: user.email }, JWT_SECRET, { expiresIn: '7d' });
      return res.status(200).json({ token, user: { id: user.id, email: user.email } });
      
    } else {
      return res.status(400).json({ error: 'Invalid action' });
    }

  } catch (error) {
    console.error('Auth error:', error);
    res.status(500).json({ error: 'Internal Server Error' });
  }
};
