<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class WilayaSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('data/wilayas.json');

        if (!File::exists($path)) {
            $this->command->error('wilayas.json not found at database/data/wilayas.json');
            return;
        }

        $wilayas = json_decode(File::get($path), true);

        if (!is_array($wilayas)) {
            $this->command->error('wilayas.json is invalid.');
            return;
        }

        Schema::disableForeignKeyConstraints();

        foreach ($wilayas as $wilaya) {
            $id = DB::table('wilayas')->updateOrInsert(
                ['code' => $wilaya['code']],
                [
                    'name_en' => $wilaya['name_en'],
                    'name_ar' => $wilaya['name_ar'],
                    'delivery_fee_home' => $wilaya['delivery_fee_home'],
                    'delivery_fee_desk' => $wilaya['delivery_fee_desk'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            $wilayaId = DB::table('wilayas')
                ->where('code', $wilaya['code'])
                ->value('id');

            DB::table('communes')->where('wilaya_id', $wilayaId)->delete();

            foreach ($wilaya['communes'] as $communeName) {
                DB::table('communes')->insert([
                    'wilaya_id' => $wilayaId,
                    'name_en' => $communeName,
                    'name_ar' => $communeName,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        Schema::enableForeignKeyConstraints();
    }
}