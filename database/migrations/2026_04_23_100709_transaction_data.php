<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        //table order
        Schema::create("tr_order", function (Blueprint $table) {
            // $table->unsignedBigInteger("pricelist_id");

            $table->string("order_number")->primary();
            $table->string("payment_number")->nullable()->unique();
            $table->unsignedBigInteger("branch_id");

            $table->unsignedBigInteger("terminal_id");
            $table->string("order_name");
            $table->string("customer_phone_number")->nullable();
            $table->string('order_source'); // pos , qr
            $table->string("order_type"); //dinein / takeaway
            $table->unsignedBigInteger('table_section_id')->nullable();
            $table->unsignedBigInteger("total_batch")->default(1); // total batch
            $table->date("order_date");
            $table->unsignedBigInteger("order_queue");
            $table->dateTime("order_in")->nullable();
            $table->dateTime("order_out")->nullable();
            $table->unsignedBigInteger("member_id")->nullable();
            $table->unsignedBigInteger("visit_purpose_id");
            $table->unsignedBigInteger("pax")->default(1);
            $table->string("status"); // pending, cancel, paid, void

            $table->string("waiter_name")->nullable();
            $table->string("sender_name")->nullable();

            $table->decimal("delivery_cost", 20, 2)->default(0);
            $table->decimal("order_fee", 20, 2)->default(0);
            $table->decimal("service_charge", 20, 2)->default(0);
            $table->decimal("platform_fee", 20, 2)->default(0);

            $table->unsignedBigInteger("total_item")->default(0);
            $table->decimal("sub_total", 20, 2)->default(0);
            $table->decimal("total_discount", 20, 2)->default(0);
            $table->decimal("total_tax", 20, 2)->default(0);
            $table->decimal("total_billing", 20, 2)->default(0);

            $table->string("cancel_notes")->nullable()->default('');
            $table->timestamp("cancel_at")->nullable();
            $table->unsignedBigInteger("cancel_by")->nullable();
            $table->unsignedBigInteger("cancel_print_ke")->default(0);

            $table->string("void_notes")->nullable()->default('');
            $table->timestamp("void_at")->nullable();
            $table->unsignedBigInteger("void_by")->nullable();
            $table->unsignedBigInteger("void_print_ke")->default(0);

            $table->timestamp("payment_at")->nullable();
            $table->unsignedBigInteger("payment_by")->nullable();
            $table->unsignedBigInteger("print_ke")->default(0);
            $table->string("payment_notes")->nullable()->default('');

            $table->timestamps();
            $table->unsignedBigInteger("created_by")->nullable();
            $table->unsignedBigInteger("updated_by")->nullable();
            $table->timestamp('sync_at')->nullable();
            //

        });

        // order detail
        Schema::create("tr_order_detail", function (Blueprint $table) {
            // $table->foreign("order_number")->references("order_number")->on("tr_order");
            // $table->foreign("menu_id")->references("id")->on("mr_menu");
            // $table->foreign("category_id")->references("id")->on("mr_category");
            // $table->foreign("subcategory_id")->references("id")->on("mr_subcategory");
            $table->ulid("ulid")->primary();

            $table->string("order_number"); //foreign key ke tr_order 
            // kelompok menu
            $table->unsignedBigInteger("pricelist_detail_id");
            $table->unsignedBigInteger("menu_id"); //item conv detail
            $table->unsignedBigInteger("category_id")->nullable();
            $table->unsignedBigInteger("subcategory_id")->nullable();
            $table->unsignedBigInteger("qty");
            $table->boolean("flag_inclusive_tax");
            $table->decimal("base_price", 20, 2);
            $table->decimal("menu_price", 20, 2);

            $table->bigInteger("tax_id")->nullable();
            $table->string("tax_type")->nullable();
            $table->decimal("tax_rate", 10, 2)->nullable()->default(0);
            $table->decimal("tax_value", 20, 2)->nullable()->default(0);

            $table->bigInteger("promo_id")->nullable();
            $table->decimal("discount_rate", 10, 2)->nullable()->default(0);
            $table->decimal("discount_value", 20, 2)->nullable()->default(0);
            $table->decimal("total", 20, 2)->nullable()->default(0);

            $table->string("notes")->nullable()->default('');

            //kelompok batching dan printer
            $table->integer("batch");
            $table->boolean('done_print')->default(false);
            $table->integer('print_ke')->default(1); //print ulang order forward stiap station
            $table->string("cancel_notes")->nullable()->default('');
            $table->timestamp("cancel_at")->nullable();
            $table->timestamps();
            $table->unsignedBigInteger("created_by")->nullable();
            $table->unsignedBigInteger("updated_by")->nullable();
            $table->timestamp('sync_at')->nullable();
        });

        Schema::create("tr_order_detail_package", function (Blueprint $table) {
            $table->ulid("ulid")->primary();
            $table->ulid('tr_order_detail_ulid');

            $table->unsignedBigInteger('menu_package_id'); // atau item_package_id
            $table->unsignedBigInteger("menu_id"); //item conv detail
            $table->unsignedBigInteger("category_id")->nullable();
            $table->unsignedBigInteger("subcategory_id")->nullable();
            $table->unsignedBigInteger("qty");
            $table->boolean("flag_inclusive_tax");
            $table->decimal("base_price", 20, 2);
            $table->decimal("menu_price", 20, 2);

            $table->bigInteger("tax_id")->nullable();
            $table->string("tax_type")->nullable();
            $table->decimal("tax_rate", 10, 2)->nullable()->default(0);
            $table->decimal("tax_value", 20, 2)->nullable()->default(0);

            $table->bigInteger("promo_id")->nullable();
            $table->decimal("discount_rate", 10, 2)->nullable()->default(0);
            $table->decimal("discount_value", 20, 2)->nullable()->default(0);
            $table->decimal("total", 20, 2)->nullable()->default(0);

            $table->string("notes")->nullable()->default('');
            $table->timestamp("sync_at")->nullable();
        });

        // payment detail
        Schema::create("tr_order_payment", function (Blueprint $table) {
            $table->id();
            $table->string("payment_number");

            $table->unsignedBigInteger("payment_method_id");
            $table->decimal("payment_amount", 20, 2);

            //voucher
            $table->string("voucher_code")->nullable();

            //card number
            $table->string("card_number")->nullable();
            $table->string("bank_name")->nullable();
            $table->string("account_name")->nullable();
            $table->string("verification_code")->nullable();
            $table->timestamps();
            $table->unsignedBigInteger("created_by")->nullable();
            $table->unsignedBigInteger("updated_by")->nullable();
            $table->timestamp('sync_at')->nullable();
        });

        // 

        // day in out
        Schema::create("tr_dayshift", function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger("branch_id");

            $table->dateTime("dayin_time")->nullable();
            $table->decimal("dayin_total", 20, 2)->default(0);
            $table->unsignedBigInteger("dayin_user_id")->nullable();

            $table->decimal("system_cash_received", 20, 2)->nullable();

            $table->dateTime("dayout_time")->nullable();
            $table->decimal("dayout_total", 20, 2)->nullable();
            $table->unsignedBigInteger("dayout_user_id")->nullable();

            $table->string("dayout_notes")->nullable();
            $table->timestamp("sync_at")->nullable();
        });

        Schema::create("tr_dayshift_detail", function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger("dayshift_id");
            $table->dateTime("shift_time");
            $table->unsignedBigInteger("shift_user_id");
            $table->timestamp("sync_at");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
        Schema::dropIfExists("tr_order");
        Schema::dropIfExists("tr_order_detail");
        Schema::dropIfExists("tr_payment");
        Schema::dropIfExists("tr_payment_detail");
        Schema::dropIfExists("tr_order_payment");
    }
};
