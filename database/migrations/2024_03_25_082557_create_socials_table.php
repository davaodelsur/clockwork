<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('socials', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id');
            $table->string('provider');
            $table->string('provider_id');
            $table->timestamps();
            $table->unique(['provider', 'provider_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('socials');
    }
};
