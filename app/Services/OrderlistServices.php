<?php

namespace App\Services;


use App\Models\TrOrderModel;


class OrderlistServices
{
  public static function getOrderlistTakaway()
  {
    try {
      $order_takaway = TrOrderModel::whereNull('cancel_at')
        ->where('order_source', 'pos')->where('order_type', 'takeaway')
        ->where('status', 'pending')->orderBy('created_at', 'desc')->get();
      return $order_takaway;
    } catch (\Throwable $e) {
      return $e;
    }
  }
}
