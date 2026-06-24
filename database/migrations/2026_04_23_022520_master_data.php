<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 
        Schema::create("mr_branch", function (Blueprint $table) {
            $table->bigInteger('id')->primary();
            $table->string("branch_code", 225);
            $table->string("branch_name", 225);
            $table->string("brand_code", 225)->nullable();
            $table->string("brand_name", 225)->nullable();
            $table->text("address")->nullable();
            $table->string("phone")->nullable();
            $table->text("printing_header")->nullable();
            $table->text("printing_footer")->nullable();
            $table->integer("company_id");
            $table->timestamps();
            // $table->string("company_code", 225)->nullable();
        });

        //

        Schema::create("mr_printer_connection", function (Blueprint $table) {
            $table->integer("id")->primary();
            $table->string("name");
        });

        DB::table('mr_printer_connection')->insert([
            [
                'id' => 1,
                'name' => 'Windows Printer Connection'
            ],
            [
                'id' => 2,
                'name' => 'Network Printer Connection'
            ],
            [
                'id' => 3,
                'name' => 'Android Bluetooth Connection'
            ],
            [
                'id' => 4,
                'name' => 'Android USB Printer Connection'
            ],
        ]);

        Schema::create("mr_printer_type", function (Blueprint $table) {
            $table->integer("id")->primary();
            $table->string("name");
        });

        DB::table('mr_printer_type')->insert([
            [
                'id' => 1,
                'name' => 'Thermal Printer'
            ],
            [
                'id' => 2,
                'name' => 'Epson Sticker'
            ],
            [
                'id' => 3,
                'name' => 'GPrinter Sticker'
            ],
        ]);

        Schema::create("mr_printing_mode", function (Blueprint $table) {
            $table->integer("id")->primary();
            $table->string("name");
        });

        DB::table('mr_printing_mode')->insert([
            [
                'id' => 1,
                'name' => 'Standar Printing'
            ],
            [
                'id' => 2,
                'name' => 'Single Menu Printing'
            ],
            [
                'id' => 3,
                'name' => 'Qty Menu Printing'
            ],
        ]);

        Schema::create("mr_station", function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->bigInteger("branch_id");
            $table->string("name")->nullable();
            $table->string("printer_name");
            $table->bigInteger("printer_type");
            $table->bigInteger("printer_connection");
            $table->bigInteger("printing_mode");
            $table->string("port");
            $table->boolean("auto_cut");
            $table->boolean("cash_drawer");
            $table->bigInteger("line_character");
            // $table->boolean("is_active")->default(true);
            $table->timestamps();
        });


        //type category
        Schema::create("mr_category_type", function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->string("name");
        });

        DB::table("mr_category_type")->insert([
            [
                'id' => 1,
                'name' => 'Inventory',
            ],
            [
                'id' => 2,
                'name' => 'Non Inventory',
            ],
            [
                'id' => 3,
                'name' => 'Asset',
            ],
            [
                'id' => 4,
                'name' => 'Non Deprecated',
            ],
            [
                'id' => 5,
                'name' => 'Menu',
            ],
        ]);

        //category
        Schema::create("mr_category", function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->string("code");
            $table->string("name");
            $table->integer("type_category_id");
        });

        //sub category
        Schema::create("mr_subcategory", function (Blueprint $table) {
            $table->unsignedBigInteger("id")->primary();
            $table->string("name");
            $table->string("code")->nullable();
            $table->text("description")->nullable();
        });

        ///////////////

        //
        Schema::create("mr_table_section", function (Blueprint $table) {
            $table->unsignedBigInteger("id")->primary();
            $table->unsignedBigInteger("branch_id");
            $table->string("name");
            $table->unsignedBigInteger("tablechecker_station_id")->nullable();
            // $table->foreign("tablechecker_station_id")->references("id")->on("mr_station");
            $table->unsignedBigInteger("mainchecker_station_id")->nullable();
            // $table->foreign("mainchecker_station_id")->references("id")->on("mr_station");
            $table->unsignedBigInteger("layout_height")->default(0);
            $table->unsignedBigInteger("layout_width")->default(0);
            $table->string("layout_image_src")->nullable();
            $table->boolean("is_active")->nullable();
            $table->boolean("can_hold")->nullable();
            $table->string("type");
        });

        // DB::table("mr_table_section")->insert([
        //     'id' => 0,
        //     'branch_id' => 0,
        //     'name' => 'QUICK SERVICE',
        //     'is_active' => true
        // ]);

        Schema::create('mr_table_section_print_category_setting', function (Blueprint $table) {
            $table->unsignedBigInteger("id")->primary();
            $table->unsignedBigInteger("table_section_id");
            $table->unsignedBigInteger("sub_category_id");
            $table->unsignedBigInteger("station_id");
            $table->unsignedBigInteger("category_id");
        });

        //
        Schema::create("mr_table", function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger("table_section_id")->nullable();
            // $table->foreign("table_section_id")->references("id")->on("mr_table_section");

            $table->string("name");
            $table->string("type");
            $table->unsignedBigInteger("height");
            $table->unsignedBigInteger("width");
            $table->unsignedBigInteger("pos_x");
            $table->unsignedBigInteger("pos_y");
            $table->unsignedBigInteger("table_seat");
            $table->decimal("minimum_billing", 20, 2);
            $table->boolean("available_for_book");
        });

        Schema::create("mr_pos_type", function (Blueprint $table) {
            $table->unsignedBigInteger("id")->primary();
            $table->string("name");
            $table->string("device_type");
        });

        DB::table("mr_pos_type")->insert([
            [
                'id' => 1,
                'name' => 'POS Desktop',
                'device_type' => 'desktop'
            ],
            [
                'id' => 2,
                'name' => 'POS Desktop',
                'device_type' => 'mobile'
            ],
        ]);


        //
        Schema::create("mr_terminal", function (Blueprint $table) {
            $table->unsignedBigInteger("id")->primary();
            $table->string("name");
            $table->unsignedBigInteger("branch_id");
            $table->string("device_id");
            $table->unsignedBigInteger("pos_type_id");
            $table->boolean("is_active");
            $table->boolean("is_used");
        });


        //tax
        Schema::create("mr_tax", function (Blueprint $table) {
            $table->unsignedBigInteger("id")->primary();
            $table->string("name");
            $table->decimal("rate", 10, 2);
        });

        // 

        //menu
        // Schema::create("mr_menu" , function(Blueprint $table){
        //     $table->unsignedBigInteger("id")->primary();
        //     $table->unsignedInteger("branch_id");
        //     $table->string("menu_code");
        //     $table->string("menu_name");
        //     $table->string("menu_shortname");

        //     $table->unsignedBigInteger("category_id")->nullable();
        //     // $table->foreign("category_id")->references("id")->on("mr_category");

        //     $table->unsignedBigInteger("subcategory_id")->nullable();
        //     // $table->foreign("subcategory_id")->references("id")->on("mr_subcategory");

        //     $table->unsignedBigInteger("bom_id");
        //     $table->string("menu_color");
        //     $table->string("image");

        //     $table->boolean("inclusive_tax");
        //     $table->string("tax_type");

        //     $table->unsignedBigInteger("tax_id")->nullable();
        //     // $table->foreign("tax_id")->references("id")->on("mr_tax");

        //     $table->decimal("tax_rate",10,2);

        //     $table->boolean("separate_print_package")->default(false);
        //     $table->boolean("separate_tax_calc")->default(false);
        //     $table->boolean("flag_soldout")->default(false);

        //     $table->decimal("menu_price", 20,2);
        //     $table->boolean("is_active");
        // });

        // Schema::create("mr_menu_package", function(Blueprint $table){
        //     $table->unsignedBigInteger("id")->primary();
        //     $table->unsignedBigInteger("item_id")''
        // });


        Schema::create("mr_item", function (Blueprint $table) {
            $table->unsignedBigInteger("id")->primary();
            $table->string("short_name")->nullable();
            $table->string("name");
            $table->string("code")->nullable();
            $table->unsignedBigInteger("category_id");
            $table->unsignedBigInteger("subcategory_id");
            $table->unsignedBigInteger("bom_id")->nullable();
            $table->string("menu_color")->nullable();
            $table->string("image")->nullable();
            $table->string("tax_type")->nullable();
            $table->unsignedBigInteger("stok_qty")->default(0);
            $table->boolean("flag_soldout")->default(false);
        });

        Schema::create("mr_item_conv", function (Blueprint $table) {
            $table->unsignedBigInteger("id")->primary();
            $table->unsignedBigInteger("item_id");
        });

        Schema::create("mr_item_package", function (Blueprint $table) {
            $table->unsignedBigInteger("id")->primary();
            $table->unsignedBigInteger("item_id");
            $table->boolean("separate_print_package")->default(false);
        });

        Schema::create("mr_item_package_group", function (Blueprint $table) {
            $table->unsignedBigInteger("id")->primary();
            $table->unsignedBigInteger("item_package_id");
            $table->string("name");
            $table->unsignedBigInteger("min_qty");
            $table->unsignedBigInteger("max_qty");
        });

        Schema::create("mr_item_package_detail", function (Blueprint $table) {
            $table->unsignedBigInteger("id")->primary();
            $table->unsignedBigInteger("package_group_id");
            $table->unsignedBigInteger("item_conv_detail_id");
            $table->decimal("price", 20, 2);
        });

        Schema::create("mr_pricelist", function (Blueprint $table) {
            $table->unsignedBigInteger("id")->primary();
            $table->string("name");
            $table->boolean("is_inclusive");
        });

        Schema::create("mr_pricelist_detail", function (Blueprint $table) {
            $table->unsignedBigInteger("id")->primary();
            $table->unsignedBigInteger("pricelist_id");
            $table->unsignedBigInteger("item_conv_detail_id");
            $table->decimal("price", 20, 2);
            $table->boolean("pos");
            $table->boolean("qr_order");
        });

        /////

        Schema::create("mr_payment_method", function (Blueprint $table) {
            $table->unsignedBigInteger("id")->primary();
            $table->string("name");
            $table->string("code");
            $table->unsignedBigInteger("coa_account_id");
            $table->unsignedBigInteger("payment_method_type_id");
            $table->decimal("mdr", 10, 2);
            $table->unsignedBigInteger("printer_count");
            $table->boolean("open_cash_drawer");
            $table->unsignedBigInteger("group_payment_id");
            $table->string("color_theme")->nullable();
            $table->boolean("use_authorization");
            $table->decimal("fixed_amount", 20, 2);
            $table->boolean("verification_code_mandatory");
            $table->boolean("card_number_code_mandatory");
        });
        //
        Schema::create("mr_payment_method_group", function (Blueprint $table) {
            $table->unsignedBigInteger("id")->primary();
            $table->string("name");
        });

        Schema::create("mr_payment_method_type", function (Blueprint $table) {
            $table->unsignedBigInteger("id")->primary();
            $table->string("name");
        });

        Schema::create("mr_payment_method_visit_purposes", function (Blueprint $table) {
            $table->unsignedBigInteger("id")->primary();
            $table->unsignedBigInteger("payment_method_id");
            $table->unsignedBigInteger("visit_purpose_id");
        });

        Schema::create("mr_branch_visit_purpose", function (Blueprint $table) {
            $table->unsignedBigInteger("id")->primary();
            $table->unsignedBigInteger("visit_purpose_id");
            $table->unsignedBigInteger("service_charge");
            $table->unsignedBigInteger("vat");
            $table->unsignedBigInteger("pb1");
            $table->decimal("order_fee", 20, 2);
            $table->unsignedBigInteger("pricelist_id");
        });

        Schema::create("mr_visit_purpose", function (Blueprint $table) {
            $table->unsignedBigInteger("id")->primary();
            $table->string("name")->nullable();
        });

        Schema::create("mr_setting", function (Blueprint $table) {

            $table->unsignedBigInteger("id");
            $table->unsignedBigInteger("default_station");
            $table->boolean("use_customer_display");
            $table->string("customer_display_name")->nullable()->default('');
            $table->bigInteger("customer_display_left");
            $table->bigInteger("customer_display_top");
        });

        DB::table("mr_setting")->insert([[
            'id' => 1,
            'default_station' => 0,
            'use_customer_display' => false,
            'customer_display_name' => '',
            'customer_display_left' => 0,
            'customer_display_top' => 0
        ]]);

        Schema::create("mr_user", function (Blueprint $table) {
            $table->unsignedBigInteger("id")->primary();
            $table->string("username");
            $table->string("fullname")->nullable();
            $table->unsignedBigInteger("role_id");
            $table->string("email");
            $table->string("sandi");
        });

        Schema::create("mr_role_access", function (Blueprint $table) {
            $table->unsignedBigInteger("id")->primary();
            $table->unsignedBigInteger("role_id");
            $table->unsignedBigInteger("menu_id");
            $table->boolean("view");
            $table->boolean("update");
            $table->boolean("insert");
            $table->boolean("delete");
            $table->boolean("approve");
        });

        Schema::create("mr_menu_app", function (Blueprint $table) {
            $table->unsignedBigInteger("id")->primary();
            $table->string("menu");
            $table->string("submenu");
        });

        Schema::create("mr_session", function (Blueprint $table) {
            $table->uuid("session_id");
            $table->string("data");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
        Schema::dropIfExists("mr_branch");
        Schema::dropIfExists("mr_station");
        Schema::dropIfExists("mr_table_section");
        Schema::dropIfExists("mr_table");
        Schema::dropIfExists("mr_terminal");
        Schema::dropIfExists("mr_category");
        Schema::dropIfExists("mr_subcategory");
        Schema::dropIfExists("mr_tax");
        Schema::dropIfExists("mr_menu");

        Schema::dropIfExists("mr_item");
        Schema::dropIfExists("mr_iteme");
        Schema::dropIfExists("mr_item_conv_detail");
        Schema::dropIfExists("mr_item_package");
        Schema::dropIfExists("mr_item_package_group");
        Schema::dropIfExists("mr_item_package_detail");

        Schema::dropIfExists("mr_pricelist");
        Schema::dropIfExists("mr_pricelist_detail");

        Schema::dropIfExists("mr_setting");
        Schema::dropIfExists("mr_payment_method");
        Schema::dropIfExists("mr_payment_method_group");
        Schema::dropIfExists("mr_payment_method_type");
        Schema::dropIfExists("mr_payment_method_visit_purpose");
        Schema::dropIfExists("mr_branch_visit_purpose");
        Schema::dropIfExists("mr_visit_purpose");

        Schema::dropIfExists("mr_user");
        Schema::dropIfExists("mr_role_access");
        Schema::dropIfExists("mr_menu_app");
        Schema::dropIfExists("mr_session");
    }
};
