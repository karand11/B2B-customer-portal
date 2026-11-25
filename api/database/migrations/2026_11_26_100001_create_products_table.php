<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Extensions are per database, so the migration creates it. down() must NOT drop it.
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('categories')->restrictOnDelete();
            $table->string('part_number', 64);
            // Generated in the database so upserts (which skip Eloquent events) can never let it drift.
            $table->string('part_number_search', 64)
                ->storedAs("lower(regexp_replace(part_number, '[^A-Za-z0-9]', '', 'g'))");
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->string('brand', 64);
            $table->decimal('inner_diameter_mm', 7, 2);
            $table->decimal('outer_diameter_mm', 7, 2);
            $table->decimal('width_mm', 7, 2);
            $table->string('material', 64);
            $table->string('seal_type', 8);
            $table->bigInteger('price_cents')->nullable();
            $table->boolean('price_on_request')->default(false);
            $table->string('stock_status', 16)->default('on_order');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique('part_number_search', 'products_part_number_search_unique');
        });

        DB::statement('ALTER TABLE products ADD CONSTRAINT products_inner_positive CHECK (inner_diameter_mm > 0)');
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_outer_gt_inner CHECK (outer_diameter_mm > inner_diameter_mm)');
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_width_positive CHECK (width_mm > 0)');
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_seal_type_check CHECK (seal_type IN ('open','2rs','zz'))");
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_stock_status_check CHECK (stock_status IN ('in_stock','low_stock','out_of_stock','on_order'))");
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_price_nonnegative CHECK (price_cents >= 0)');
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_price_consistent CHECK ((price_on_request AND price_cents IS NULL) OR (NOT price_on_request AND price_cents IS NOT NULL))');

        // Partial indexes: every catalog query has active = true. The schema builder cannot express these.
        // The trigram index only helps when the normalised query has 3+ characters; shorter ones scan (fine at this size).
        DB::statement('CREATE INDEX products_part_number_search_trgm ON products USING gin (part_number_search gin_trgm_ops) WHERE active');
        DB::statement('CREATE INDEX products_category_size_idx ON products (category_id, outer_diameter_mm, inner_diameter_mm) WHERE active');
        DB::statement('CREATE INDEX products_brand_category_idx ON products (brand, category_id) WHERE active');
        DB::statement('CREATE INDEX products_size_idx ON products (inner_diameter_mm, outer_diameter_mm, width_mm) WHERE active');
        DB::statement('CREATE INDEX products_material_idx ON products (material) WHERE active');
        DB::statement('CREATE INDEX products_seal_type_idx ON products (seal_type) WHERE active');
        DB::statement('CREATE INDEX products_stock_status_idx ON products (stock_status) WHERE active');
        DB::statement('CREATE INDEX products_price_idx ON products (price_cents) WHERE active');
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
