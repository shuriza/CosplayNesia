const { getDb } = require('../db/database');
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

  const authHeader = req.headers.authorization;
  if (!authHeader || !authHeader.startsWith('Bearer ')) {
    return res.status(401).json({ error: 'Unauthorized' });
  }

  const token = authHeader.split(' ')[1];
  let user;
  try {
    user = jwt.verify(token, JWT_SECRET);
  } catch (err) {
    return res.status(401).json({ error: 'Invalid token' });
  }

  const { items } = req.body;
  if (!items || !items.length) {
    return res.status(400).json({ error: 'Cart is empty' });
  }

  try {
    const db = await getDb();
    
    // We should use transaction, but sqlite module doesn't support them easily out of box with async/await
    // Let's do simple sequential updates
    await db.exec('BEGIN TRANSACTION');

    let totalAmount = 0;
    
    // Validate stock and calculate total
    for (const item of items) {
      const product = await db.get('SELECT * FROM products WHERE id = ?', item.id);
      if (!product || product.stock < 1) {
        await db.exec('ROLLBACK');
        return res.status(400).json({ error: `Produk ${item.name || 'item'} kehabisan stok.` });
      }
      totalAmount += product.price;
    }

    // Create order
    const result = await db.run('INSERT INTO orders (user_id, total_amount, status) VALUES (?, ?, ?)', user.id, totalAmount, 'SUCCESS');
    const orderId = result.lastID;

    // Create order items & reduce stock
    const stmtItem = await db.prepare('INSERT INTO order_items (order_id, product_id, price) VALUES (?, ?, ?)');
    const stmtStock = await db.prepare('UPDATE products SET stock = stock - 1 WHERE id = ?');
    
    for (const item of items) {
      await stmtItem.run(orderId, item.id, item.price);
      await stmtStock.run(item.id);
    }
    
    await stmtItem.finalize();
    await stmtStock.finalize();

    await db.exec('COMMIT');

    res.status(200).json({ message: 'Checkout successful', orderId });
  } catch (error) {
    console.error('Checkout error:', error);
    try {
      const db = await getDb();
      await db.exec('ROLLBACK');
    } catch(e) {}
    res.status(500).json({ error: 'Internal Server Error' });
  }
};
