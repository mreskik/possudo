<?php


namespace App\Services;

use App\Models\MasterTaxModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MenuServices
{
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
  mi.bom_id as bomId


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






    foreach ($branch_visit_purpose as $ibvp) {

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
      ", [$ibvp->menuPriceListId]);

      $subcategory = DB::select("
      SELECT DISTINCT 
      mi.category_id as categoryId,
      mi.subcategory_id as subCategoryId,
      msc.name as SubCategoryName

      FROM mr_pricelist_detail mpd
      JOIN mr_pricelist mp on mp.id = mpd.pricelist_id
      JOIN mr_item_conv mic on mic.id = mpd.item_conv_detail_id 
      JOIN mr_item mi on mi.id = mic.item_id
      JOIN mr_subcategory msc on msc.id = mi.subcategory_id

      WHERE mpd.pricelist_id = ?", [$ibvp->menuPriceListId]);

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
        mi.flag_soldout as flagSoldOut

        FROM mr_pricelist_detail mpd
        JOIN mr_pricelist mp on mp.id = mpd.pricelist_id
        JOIN mr_item_conv mic on mic.id = mpd.item_conv_detail_id 
        JOIN mr_item mi on mi.id = mic.item_id

        WHERE mpd.pricelist_id = ? and mpd.pos = true
      ", [$ibvp->menuPriceListId]);


      // tarik item package
      foreach ($listmenu as $itemmenu) {
        $itemmenu->packageList = [];
        $package = DB::select("SELECT id, item_id, separate_print_package as separatePrintPackage FROM mr_item_package WHERE item_id = ?", [$itemmenu->itemid_real]);
        foreach ($package as $pa) {
          if ($pa->item_id == $itemmenu->itemid_real) {
            $itemmenu->packageid_real = $pa->id;
            $itemmenu->separatePrintPackage = $pa->separatePrintPackage;
          }
        }

        foreach ($menuPackageGroup as $mpg) {
          if ($mpg->item_package_id == $itemmenu->packageid_real) {


            $cloning_mpg = unserialize(serialize($mpg));

            unset($cloning_mpg->item_package_id);


            //tax for submenu
            foreach ($cloning_mpg->menuPackageList as $mpl) {
              if ($mpl->taxType == 'vat') {
                if ($ibvp->vat == 0 || $ibvp->vat === null) {
                  $mpl->taxId = null;
                } else {
                  $mpl->taxId = $ibvp->vat;
                }
              } else if ($mpl->taxType == 'pb1') {
                if ($ibvp->pb1 == 0 || $ibvp->pb1 === null) {
                  $mpl->taxId = null;
                } else {
                  $mpl->taxId = $ibvp->pb1;
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

            $itemmenu->packageList[] = $cloning_mpg;
          }
        }

        // 

      }

      //tarik 



      //gabungkan menu ke subcategory
      foreach ($subcategory as $itemsub) {
        $itemsub->menuList = [];
        foreach ($listmenu as $itemmenu) {
          if ($itemmenu->subCategoryId == $itemsub->subCategoryId && $itemmenu->categoryId == $itemsub->categoryId) {

            if ($itemmenu->taxType == 'vat') {
              if ($ibvp->vat == 0) {
                $itemmenu->taxId = null;
              } else {
                $itemmenu->taxId = $ibvp->vat;
              }
            } else
            if ($itemmenu->taxType == 'pb1') {
              if ($ibvp->pb1 == 0) {
                $itemmenu->taxId = null;
              } else {
                $itemmenu->taxId = $ibvp->pb1;
              }
            } else {
              $itemmenu->taxId = null;
            }

            if ($itemmenu->taxId != 0 and $itemmenu->taxId !== null) {
              $itemmenu->taxRate = $tax[$itemmenu->taxId][0]->rate;
            } else {
              $itemmenu->taxRate = null;
            }

            //ngubah null di bom id
            if ($itemmenu->bomId == 0) {
              $itemmenu->bomId = null;
            }


            $clonemenu = unserialize(serialize($itemmenu));
            unset($clonemenu->categoryId);
            unset($clonemenu->subCategoryId);
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
      $ibvp->menuPriceList = $category;
    }

    return $branch_visit_purpose;
  }
}
