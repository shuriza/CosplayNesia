<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $products = [
            [1, 'Furina Fontaine Premium Set', 'Genshin Impact', 'Game', 145000, 'Sewa', 'M-L', 'Kitsune Wardrobe', 'Bandung', 98, 'Best seller', 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?auto=format&fit=crop&w=700&q=85', 3],
            [2, 'Kafka Stellaron Hunter', 'Honkai: Star Rail', 'Game', 165000, 'Sewa', 'M', 'Astral Cosrent', 'Jakarta', 91, 'Populer', 'https://images.unsplash.com/photo-1488426862026-3ee34a7d66df?auto=format&fit=crop&w=700&q=85', 2],
            [3, 'Frieren Full Costume & Wig', 'Sousou no Frieren', 'Anime', 185000, 'Sewa', 'S-M', 'Moonlight Rent', 'Surabaya', 96, 'Baru', 'https://images.unsplash.com/photo-1524504388940-b1c1722653e1?auto=format&fit=crop&w=700&q=85', 5],
            [4, 'Maomao Apothecary Set', 'Kusuriya no Hitorigoto', 'Anime', 120000, 'Sewa', 'M', 'Hanami Costume', 'Yogyakarta', 88, 'Baru', 'https://images.unsplash.com/photo-1544005313-94ddf0286df2?auto=format&fit=crop&w=700&q=85', 1],
            [5, 'Hatsune Miku Magical Mirai', 'Vocaloid', 'VTuber', 210000, 'Sewa', 'M-L', 'Neo Cosplay', 'Tangerang', 85, 'Premium', 'https://images.unsplash.com/photo-1531123897727-8f129e1688ce?auto=format&fit=crop&w=700&q=85', 1],
            [6, 'Firefly Complete Battle Suit', 'Honkai: Star Rail', 'Game', 195000, 'Sewa', 'S-M', 'Astral Cosrent', 'Jakarta', 94, 'Populer', 'https://images.unsplash.com/photo-1531746020798-e6953c6e8e04?auto=format&fit=crop&w=700&q=85', 2],
            [7, 'Raiden Shogun Deluxe', 'Genshin Impact', 'Game', 175000, 'Sewa', 'L', 'Kitsune Wardrobe', 'Bandung', 89, 'Best seller', 'https://images.unsplash.com/photo-1517841905240-472988babdf9?auto=format&fit=crop&w=700&q=85', 4],
            [8, 'Marcille Dungeon Meshi', 'Dungeon Meshi', 'Anime', 135000, 'Sewa', 'M', 'Clover Cosrent', 'Malang', 83, 'Baru', 'https://images.unsplash.com/photo-1494790108377-be9c29b29330?auto=format&fit=crop&w=700&q=85', 1],
            [9, 'Elf Ears Handmade Premium', 'Fantasy Props', 'Aksesoris', 89000, 'Beli', 'All size', 'Prop Lab ID', 'Depok', 80, 'Handmade', 'https://images.unsplash.com/photo-1614583225154-5fcdda07019e?auto=format&fit=crop&w=700&q=85', 15],
            [10, 'Silver Fantasy Wig 80cm', 'Wig Collection', 'Aksesoris', 265000, 'Beli', 'All size', 'Wigcraft Studio', 'Semarang', 76, 'Premium', 'https://images.unsplash.com/photo-1524250502761-1ac6f2e30d43?auto=format&fit=crop&w=700&q=85', 5],
            [11, "Ninomae Ina'nis Casual", 'Hololive', 'VTuber', 155000, 'Sewa', 'M', 'Holo Closet', 'Bekasi', 82, 'Populer', 'https://images.unsplash.com/photo-1529139574466-a303027c1d8b?auto=format&fit=crop&w=700&q=85', 1],
            [12, 'Anya Forger Eden Uniform', 'Spy x Family', 'Anime', 95000, 'Sewa', 'S', 'Peanut Cosrent', 'Solo', 74, 'Hemat', 'https://images.unsplash.com/photo-1512316609839-ce289d3eba0a?auto=format&fit=crop&w=700&q=85', 2],
        ];

        foreach ($products as $product) {
            Product::updateOrCreate(['id' => $product[0]], [
                'name' => $product[1], 'series' => $product[2], 'category' => $product[3],
                'price' => $product[4], 'type' => $product[5], 'size' => $product[6],
                'seller' => $product[7], 'city' => $product[8], 'popular' => $product[9],
                'badge' => $product[10], 'image' => $product[11], 'stock' => $product[12],
            ]);
        }
    }
}
