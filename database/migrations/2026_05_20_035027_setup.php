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

        Schema::create("setup_config", function(Blueprint $table){
            $table->unsignedBigInteger("id")->primary();
            $table->boolean("flag_install_status")->default(false);
        });

        DB::table("setup_config")->insert([
            [
                "id"=>1,
                "flag_install_status"=>false,  
            ]
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
        Schema::dropIfExists("setup_config");
    }
};
