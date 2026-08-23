const sqlite3 = require('sqlite3');
const { open } = require('sqlite');
const path = require('path');
const fs = require('fs');

const dbPath = path.join(process.cwd(), 'db', 'cosplaynesia.sqlite');

let dbPromise = null;

async function getDb() {
  if (dbPromise) return dbPromise;
  
  dbPromise = open({
    filename: dbPath,
    driver: sqlite3.Database
  }).then(async (db) => {
    await db.exec(`
      CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT UNIQUE NOT NULL,
        password TEXT NOT NULL,
        name TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
      );

      CREATE TABLE IF NOT EXISTS products (
        id INTEGER PRIMARY KEY,
        name TEXT NOT NULL,
        series TEXT,
        category TEXT,
        price INTEGER,
        type TEXT,
        size TEXT,
        seller TEXT,
        city TEXT,
        rating REAL,
        popular INTEGER,
        newest INTEGER,
        badge TEXT,
        image TEXT,
        stock INTEGER DEFAULT 1
      );

      CREATE TABLE IF NOT EXISTS orders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        total_amount INTEGER,
        status TEXT DEFAULT 'PENDING',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(user_id) REFERENCES users(id)
      );

      CREATE TABLE IF NOT EXISTS order_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER,
        product_id INTEGER,
        price INTEGER,
        FOREIGN KEY(order_id) REFERENCES orders(id),
        FOREIGN KEY(product_id) REFERENCES products(id)
      );
    `);

    // Seed products if empty
    const productCount = await db.get('SELECT COUNT(*) as count FROM products');
    if (productCount.count === 0) {
      const initialProducts = [
        { id: 1, name: "Furina Fontaine Premium Set", series: "Genshin Impact", category: "Game", price: 145000, type: "Sewa", size: "M-L", seller: "Kitsune Wardrobe", city: "Bandung", rating: 4.9, popular: 98, newest: 8, badge: "Best seller", image: "https://images.unsplash.com/photo-1534528741775-53994a69daeb?auto=format&fit=crop&w=700&q=85", stock: 3 },
        { id: 2, name: "Kafka Stellaron Hunter", series: "Honkai: Star Rail", category: "Game", price: 165000, type: "Sewa", size: "M", seller: "Astral Cosrent", city: "Jakarta", rating: 4.8, popular: 91, newest: 7, badge: "Populer", image: "https://images.unsplash.com/photo-1488426862026-3ee34a7d66df?auto=format&fit=crop&w=700&q=85", stock: 2 },
        { id: 3, name: "Frieren Full Costume & Wig", series: "Sousou no Frieren", category: "Anime", price: 185000, type: "Sewa", size: "S-M", seller: "Moonlight Rent", city: "Surabaya", rating: 5.0, popular: 96, newest: 12, badge: "Baru", image: "https://images.unsplash.com/photo-1524504388940-b1c1722653e1?auto=format&fit=crop&w=700&q=85", stock: 5 },
        { id: 4, name: "Maomao Apothecary Set", series: "Kusuriya no Hitorigoto", category: "Anime", price: 120000, type: "Sewa", size: "M", seller: "Hanami Costume", city: "Yogyakarta", rating: 4.9, popular: 88, newest: 10, badge: "Baru", image: "https://images.unsplash.com/photo-1544005313-94ddf0286df2?auto=format&fit=crop&w=700&q=85", stock: 1 },
        { id: 5, name: "Hatsune Miku Magical Mirai", series: "Vocaloid", category: "VTuber", price: 210000, type: "Sewa", size: "M-L", seller: "Neo Cosplay", city: "Tangerang", rating: 4.7, popular: 85, newest: 6, badge: "Premium", image: "https://images.unsplash.com/photo-1531123897727-8f129e1688ce?auto=format&fit=crop&w=700&q=85", stock: 1 },
        { id: 6, name: "Firefly Complete Battle Suit", series: "Honkai: Star Rail", category: "Game", price: 195000, type: "Sewa", size: "S-M", seller: "Astral Cosrent", city: "Jakarta", rating: 4.9, popular: 94, newest: 9, badge: "Populer", image: "https://images.unsplash.com/photo-1531746020798-e6953c6e8e04?auto=format&fit=crop&w=700&q=85", stock: 2 },
        { id: 7, name: "Raiden Shogun Deluxe", series: "Genshin Impact", category: "Game", price: 175000, type: "Sewa", size: "L", seller: "Kitsune Wardrobe", city: "Bandung", rating: 4.8, popular: 89, newest: 5, badge: "Best seller", image: "https://images.unsplash.com/photo-1517841905240-472988babdf9?auto=format&fit=crop&w=700&q=85", stock: 4 },
        { id: 8, name: "Marcille Dungeon Meshi", series: "Dungeon Meshi", category: "Anime", price: 135000, type: "Sewa", size: "M", seller: "Clover Cosrent", city: "Malang", rating: 4.8, popular: 83, newest: 11, badge: "Baru", image: "https://images.unsplash.com/photo-1494790108377-be9c29b29330?auto=format&fit=crop&w=700&q=85", stock: 1 },
        { id: 9, name: "Elf Ears Handmade Premium", series: "Fantasy Props", category: "Aksesoris", price: 89000, type: "Beli", size: "All size", seller: "Prop Lab ID", city: "Depok", rating: 4.9, popular: 80, newest: 4, badge: "Handmade", image: "https://images.unsplash.com/photo-1614583225154-5fcdda07019e?auto=format&fit=crop&w=700&q=85", stock: 15 },
        { id: 10, name: "Silver Fantasy Wig 80cm", series: "Wig Collection", category: "Aksesoris", price: 265000, type: "Beli", size: "All size", seller: "Wigcraft Studio", city: "Semarang", rating: 4.7, popular: 76, newest: 3, badge: "Premium", image: "https://images.unsplash.com/photo-1524250502761-1ac6f2e30d43?auto=format&fit=crop&w=700&q=85", stock: 5 },
        { id: 11, name: "Ninomae Ina'nis Casual", series: "Hololive", category: "VTuber", price: 155000, type: "Sewa", size: "M", seller: "Holo Closet", city: "Bekasi", rating: 4.9, popular: 82, newest: 2, badge: "Populer", image: "https://images.unsplash.com/photo-1529139574466-a303027c1d8b?auto=format&fit=crop&w=700&q=85", stock: 1 },
        { id: 12, name: "Anya Forger Eden Uniform", series: "Spy x Family", category: "Anime", price: 95000, type: "Sewa", size: "S", seller: "Peanut Cosrent", city: "Solo", rating: 4.8, popular: 74, newest: 1, badge: "Hemat", image: "https://images.unsplash.com/photo-1512316609839-ce289d3eba0a?auto=format&fit=crop&w=700&q=85", stock: 2 }
      ];

      const stmt = await db.prepare('INSERT INTO products (id, name, series, category, price, type, size, seller, city, rating, popular, newest, badge, image, stock) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
      for (const p of initialProducts) {
        await stmt.run(p.id, p.name, p.series, p.category, p.price, p.type, p.size, p.seller, p.city, p.rating, p.popular, p.newest, p.badge, p.image, p.stock);
      }
      await stmt.finalize();
    }

    return db;
  });

  return dbPromise;
}

module.exports = { getDb };
