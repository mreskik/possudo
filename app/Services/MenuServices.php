<?php


namespace App\Services;

use App\Models\MasterTaxModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MenuServices
{
  /**
   * Naming note: there is no `mr_menu` table. Everything called "menu"/"item" here is actually
   * keyed by `mr_item_conv.id` (aliased `itemId`/`menuId` throughout this codebase, including the
   * `tr_order_detail.menu_id` column) — `mr_item_conv` itself has no name, so display fields
   * (menuName, menuCode, menuColor, ...) are always pulled from the parent `mr_item` row instead.
   * `itemid_real` below is the actual `mr_item.id`, used only internally to look up `mr_item_package`.
   */
  public static function GetMasterMenuList()
  {
    $branch_visit_purpose =  DB::select("SELECT
    bvp.visit_purpose_id as id,
    mvp.name as nameVisitPurpose,
    bvp.service_charge as serviceCharge,
    bvp.vat,
    bvp.pb1,
    bvp.order_fee as orderFee,
    bvp.pricelist_id as menuPriceListId

    FROM mr_branch_visit_purpose bvp
    JOIN mr_visit_purpose mvp on mvp.id = bvp.visit_purpose_id");

    $tax = MasterTaxModel::get()->groupBy('id');


    // SEMENTARA HOLD DULU LAGNSUNG GABUNG DI PER PRICELIST 
    //data item package
    // $menuPackage = DB::select("
    // SELECT
    // id as package_id_bantuan,
    // item_id,
    // separate_print_package as separatePrintPackage
    // from mr_item_package");



    //data item package group
    $menuPackageGroup = DB::select("
  SELECT 
  id as packageId,
  item_package_id,
  name as packageName,
  min_qty as minQty,
  max_qty as maxQty
  FROM mr_item_package_group");


    //data item package detail
    $menuPackageDetail = DB::select("SELECT 
  mipd.id as menuPackageId,
  mipd.package_group_id,
  mipd.item_conv_detail_id as itemId,
  mi.name as menuName,
  mipd.price as menuPrice,
  mi.tax_type as taxType,
  mi.bom_id as bomId,
  mi.icon_src as iconSrc


  FROM mr_item_package_detail mipd
  JOIN mr_item_conv mic on mic.id = mipd.item_conv_detail_id
  JOIN mr_item mi on mi.id = mic.item_id
  ");

    foreach ($menuPackageGroup as $sit) {
      $sit->menuPackageList = [];
      foreach ($menuPackageDetail as $menu) {
        if ($sit->packageId == $menu->package_group_id) {
          $cloning_men =  unserialize(serialize($menu));
          unset($cloning_men->package_group_id);
          $sit->menuPackageList[] = $cloning_men;
        }
      }
    }






    foreach ($branch_visit_purpose as $visitPurposeRow) {

      $category = DB::select("
      SELECT DISTINCT 
      mi.category_id as categoryId,
      mc.name as categoryName

      FROM mr_pricelist_detail mpd
      JOIN mr_pricelist mp on mp.id = mpd.pricelist_id
      JOIN mr_item_conv mic on mic.id = mpd.item_conv_detail_id 
      JOIN mr_item mi on mi.id = mic.item_id
      JOIN mr_category mc on mc.id = mi.category_id
      WHERE mpd.pricelist_id = ?
      ", [$visitPurposeRow->menuPriceListId]);

      $subcategory = DB::select("
      SELECT DISTINCT
      mi.category_id as categoryId,
      mi.subcategory_id as subCategoryId,
      msc.name as SubCategoryName,
      msc.icon_src as subCategoryIconSrc

      FROM mr_pricelist_detail mpd
      JOIN mr_pricelist mp on mp.id = mpd.pricelist_id
      JOIN mr_item_conv mic on mic.id = mpd.item_conv_detail_id
      JOIN mr_item mi on mi.id = mic.item_id
      JOIN mr_subcategory msc on msc.id = mi.subcategory_id

      WHERE mpd.pricelist_id = ?", [$visitPurposeRow->menuPriceListId]);

      // itemId here is mr_item_conv.id (pricing is set per item_conv, not per item) — itemid_real is the actual mr_item.id
      $listmenu = DB::select("
        SELECT
        mpd.id as menuPricelistId,
        mpd.item_conv_detail_id as itemId,
        mi.id as itemid_real,
        mi.code as menuCode,
        mi.name as menuName,
        mi.menu_color as menuColor,
        mi.bom_id as bomId,
        mi.category_id as categoryId,
        mi.subcategory_id as subCategoryId,
        mpd.price as menuPrice,
        mp.is_inclusive as flagInclusiveTax,
        mi.tax_type as taxType,
        mi.stok_qty as stokQty,
        mi.flag_soldout as flagSoldOut,
        mi.image as imageSrc,
        mi.icon_src as iconSrc

        FROM mr_pricelist_detail mpd
        JOIN mr_pricelist mp on mp.id = mpd.pricelist_id
        JOIN mr_item_conv mic on mic.id = mpd.item_conv_detail_id 
        JOIN mr_item mi on mi.id = mic.item_id

        WHERE mpd.pricelist_id = ? and mpd.pos = true
      ", [$visitPurposeRow->menuPriceListId]);


      // tarik item package
      foreach ($listmenu as $itemConvRow) {
        $itemConvRow->packageList = [];
        $package = DB::select("SELECT id, item_id, separate_print_package as separatePrintPackage FROM mr_item_package WHERE item_id = ?", [$itemConvRow->itemid_real]);
        foreach ($package as $pa) {
          if ($pa->item_id == $itemConvRow->itemid_real) {
            $itemConvRow->packageid_real = $pa->id;
            $itemConvRow->separatePrintPackage = $pa->separatePrintPackage;
          }
        }

        foreach ($menuPackageGroup as $mpg) {
          if ($mpg->item_package_id == $itemConvRow->packageid_real) {


            $cloning_mpg = unserialize(serialize($mpg));

            unset($cloning_mpg->item_package_id);


            //tax for submenu
            foreach ($cloning_mpg->menuPackageList as $mpl) {
              if ($mpl->taxType == 'vat') {
                if ($visitPurposeRow->vat == 0 || $visitPurposeRow->vat === null) {
                  $mpl->taxId = null;
                } else {
                  $mpl->taxId = $visitPurposeRow->vat;
                }
              } else if ($mpl->taxType == 'pb1') {
                if ($visitPurposeRow->pb1 == 0 || $visitPurposeRow->pb1 === null) {
                  $mpl->taxId = null;
                } else {
                  $mpl->taxId = $visitPurposeRow->pb1;
                }
              } else {
                $mpl->taxId = null;
              }

              //taxrate
              if ($mpl->taxId != 0 and $mpl->taxId !== null) {
                $mpl->taxRate = $tax[$mpl->taxId][0]->rate;
              } else {
                $mpl->taxRate = null;
              }

              //ngubah null di bom id
              if ($mpl->bomId == 0) {
                $mpl->bomId = null;
              }
            }

            $itemConvRow->packageList[] = $cloning_mpg;
          }
        }

        // 

      }

      //tarik 



      //gabungkan menu ke subcategory
      foreach ($subcategory as $itemsub) {
        $itemsub->menuList = [];
        foreach ($listmenu as $itemConvRow) {
          if ($itemConvRow->subCategoryId == $itemsub->subCategoryId && $itemConvRow->categoryId == $itemsub->categoryId) {

            if ($itemConvRow->taxType == 'vat') {
              if ($visitPurposeRow->vat == 0) {
                $itemConvRow->taxId = null;
              } else {
                $itemConvRow->taxId = $visitPurposeRow->vat;
              }
            } else
            if ($itemConvRow->taxType == 'pb1') {
              if ($visitPurposeRow->pb1 == 0) {
                $itemConvRow->taxId = null;
              } else {
                $itemConvRow->taxId = $visitPurposeRow->pb1;
              }
            } else {
              $itemConvRow->taxId = null;
            }

            if ($itemConvRow->taxId != 0 and $itemConvRow->taxId !== null) {
              $itemConvRow->taxRate = $tax[$itemConvRow->taxId][0]->rate;
            } else {
              $itemConvRow->taxRate = null;
            }

            //ngubah null di bom id
            if ($itemConvRow->bomId == 0) {
              $itemConvRow->bomId = null;
            }


            $clonemenu = unserialize(serialize($itemConvRow));
            // categoryId/subCategoryId/itemid_real dipertahankan (tidak di-unset) supaya
            // frontend bisa match item ke target promo (mr_promo_categories/sub_categories/items)
            $itemsub->menuList[] = $clonemenu;
          }
        }
      }

      //gabungkan subcategory ke category
      foreach ($category as $itemcat) {
        $itemcat->subCategoryData = [];
        foreach ($subcategory as $itemsubcat) {
          if ($itemcat->categoryId == $itemsubcat->categoryId) {
            $cloningan = unserialize(serialize($itemsubcat));
            unset($cloningan->categoryId);
            $itemcat->subCategoryData[] = $cloningan;
          }
        }
      }

      //masukkan semua haha
      $visitPurposeRow->menuPriceList = $category;
    }

    return $branch_visit_purpose;
  }
}
