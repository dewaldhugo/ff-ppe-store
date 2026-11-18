<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\StorefrontBrand;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Lunar\Models\Brand;

/**
 * The storefront's brand presentation: one tile per brand on /brands, with
 * the Lunar brands it covers, its logo (public/images/brands) and the tile
 * colour behind the logo (null is white). Safe to run again: it updates by
 * slug and only ever adds or moves members.
 *
 * Members are matched to Lunar brands by name, ignoring case, because Lunar
 * brands have no handle. A name that matches no Lunar brand is skipped, so
 * the seeder also runs on a database without every vendor brand.
 */
final class StorefrontBrandSeeder extends Seeder
{
    /**
     * Slug, name, Lunar brand names, logo path, logo background (hex without #).
     *
     * @var list<array{0: string, 1: string, 2: list<string>, 3: ?string, 4: ?string}>
     */
    private const array BRANDS = [
        ['maxguard', 'Maxguard', ['Maxguard'], null, null],
        ['safety-jogger', 'Safety Jogger', ['Safety Jogger', 'Safety Jogger AC/DC', 'Safety Jogger Mickey', 'Safety Jogger Adventure'], 'images/brands/safety-jogger.png', '2D2926'],
        ['oxypas', 'Oxypas', ['Oxypas'], 'images/brands/oxypas.png', null],
        ['safety-workwear', 'Safety Workwear', ['Safety Workwear'], null, null],
        ['tiger-grip', 'Tiger Grip', ['Tiger Grip'], null, null],
        ['gbs', 'GBS', ['GBS', 'GBS Med'], null, null],
        ['mirak', 'Mirak', ['Mirak'], null, null],
        ['centek', 'Centek', ['Centek'], null, null],
        ['hi-tec', 'Hi-Tec', ['Hi-Tec'], 'images/brands/hi-tec.png', null],
        ['magnum', 'Magnum', ['Magnum'], 'images/brands/magnum.png', null],
        ['dr-martens', 'Dr Martens', ['Dr Martens'], 'images/brands/dr-martens.png', null],
        ['amblers', 'Amblers', ['Amblers', 'Amblers Safety'], 'images/brands/amblers.png', null],
        ['dikamar', 'Dikamar', ['Dikamar'], null, null],
        ['dasco', 'Dasco', ['Dasco'], null, null],
        ['cincasa', 'Cincasa', ['Cincasa'], null, null],
        ['group-five', 'Group Five', ['Group Five'], null, null],
        ['mitre', 'Mitre', ['Mitre'], 'images/brands/mitre.png', null],
        ['caterpillar', 'Caterpillar', ['Caterpillar'], 'images/brands/caterpillar.png', 'FFCD05'],
        ['dunlop', 'Dunlop', ['Dunlop'], 'images/brands/dunlop.png', null],
        ['cotswold', 'Cotswold', ['Cotswold'], null, null],
        ['yachtmaster', 'Yachtmaster', ['Yachtmaster'], null, null],
        ['celia-ruiz', 'Celia Ruiz', ['Celia Ruiz'], null, null],
        ['fleet-foster', 'Fleet & Foster', ['Fleet & Foster'], null, null],
        ['glagla', 'Glagla', ['Glagla'], null, null],
        ['surf', 'Surf', ['Surf'], null, null],
        ['divaz', 'Divaz', ['Divaz'], null, null],
        ['cherry-blossom', 'Cherry Blossom', ['Cherry Blossom'], null, null],
        ['the-flexx', 'The Flexx', ['The Flexx'], 'images/brands/the-flexx.png', null],
        ['blundstone', 'Blundstone', ['Blundstone'], 'images/brands/blundstone.png', '002D72'],
        ['us-brass', 'US Brass', ['US Brass'], null, null],
        ['nora', 'Nora', ['Nora'], null, null],
        ['puma', 'Puma', ['Puma Safety', 'Puma Workwear'], 'images/brands/puma.png', null],
        ['stanley', 'Stanley', ['Stanley'], 'images/brands/stanley.png', 'FBD504'],
        ['grainger', 'Grainger', ['Grainger'], null, null],
        ['skechers', 'Skechers', ['Skechers Workwear', 'Skechers Performance'], 'images/brands/skechers.png', null],
        ['riva', 'Riva', ['Riva'], null, null],
        ['fantasy', 'Fantasy', ['Fantasy'], null, null],
        ['albatros', 'Albatros', ['Albatros'], 'images/brands/albatros.png', null],
        ['steel-flex', 'Steel Flex', ['Steel Flex'], null, null],
        ['timberland-pro', 'Timberland Pro', ['Timberland Pro'], 'images/brands/timberland-pro.png', null],
        ['shoes-for-crews', 'Shoes For Crews', ['Shoes For Crews'], null, null],
        ['sperry', 'Sperry', ['Sperry'], 'images/brands/sperry.png', null],
        ['the-clean-boot', 'The Clean Boot', ['The Clean Boot'], null, null],
        ['fitflop', 'Fitflop', ['Fitflop'], 'images/brands/fitflop.png', null],
        ['dune', 'Dune', ['Dune'], 'images/brands/dune.png', null],
        ['boudavida', 'Boudavida', ['Boudavida'], null, null],
        ['hard-yakka', 'Hard Yakka', ['Hard Yakka'], 'images/brands/hard-yakka.png', 'EC1C24'],
        ['superga', 'Superga', ['Superga'], 'images/brands/superga.png', null],
        ['helly-hansen', 'Helly Hansen', ['Helly Hansen Workwear', 'Helly Hansen Sport'], 'images/brands/helly-hansen.png', 'C71620'],
        ['umbro', 'Umbro', ['Umbro'], 'images/brands/umbro.png', null],
        ['fento', 'Fento', ['Fento'], null, null],
        ['dickies', 'Dickies', ['Dickies'], 'images/brands/dickies.png', null],
        ['herschel-bags', 'Herschel Bags', ['Herschel Bags'], 'images/brands/herschel-bags.png', null],
        ['heydude', 'HEYDUDE', ['HEYDUDE'], null, null],
        ['ted-baker', 'Ted Baker', ['Ted Baker', 'Ted Baker Sport'], 'images/brands/ted-baker.png', null],
        ['castore', 'Castore', ['Castore'], 'images/brands/castore.png', null],
        ['mascot', 'Mascot', ['Mascot'], 'images/brands/mascot.png', '000000'],
        ['all-saints', 'All Saints', ['All Saints'], 'images/brands/all-saints.png', null],
        ['jack-jones', 'Jack & Jones', ['Jack & Jones'], 'images/brands/jack-jones.png', null],
        ['juicy-couture', 'Juicy Couture', ['Juicy Couture'], 'images/brands/juicy-couture.png', null],
        ['vivienne-westwood', 'Vivienne Westwood', ['Vivienne Westwood'], 'images/brands/vivienne-westwood.png', null],
        ['only', 'ONLY', ['ONLY'], 'images/brands/only.png', null],
        ['columbia', 'Columbia', ['Columbia'], 'images/brands/columbia.png', null],
        ['hunter-safety', 'Hunter Safety', ['Hunter Safety'], 'images/brands/hunter-safety.png', null],
        ['u-power', 'U-Power', ['U-Power'], 'images/brands/u-power.png', null],
        ['karen-millen', 'Karen Millen', ['Karen Millen'], 'images/brands/karen-millen.png', null],
        ['reebok', 'Reebok', ['Reebok'], 'images/brands/reebok.png', null],
        ['florence-by-mills', 'Florence By Mills', ['Florence By Mills'], 'images/brands/florence-by-mills.png', null],
        ['aigle', 'Aigle', ['Aigle'], 'images/brands/aigle.png', null],
        ['bearpaw', 'Bearpaw', ['Bearpaw'], 'images/brands/bearpaw.png', null],
        ['cruyff-shoes', 'Cruyff Shoes', ['Cruyff Shoes'], 'images/brands/cruyff-shoes.png', null],
        ['zavetti-canada', 'Zavetti Canada', ['Zavetti Canada'], null, null],
        ['dewalt', 'Dewalt', ['Dewalt'], 'images/brands/dewalt.png', null],
    ];

    public function run(): void
    {
        $brandIds = Brand::query()->get(['id', 'name'])
            ->mapWithKeys(fn (Brand $brand): array => [mb_strtolower(trim((string) $brand->name)) => $brand->id])
            ->all();

        DB::transaction(function () use ($brandIds): void {
            foreach (self::BRANDS as [$slug, $name, $members, $logo, $background]) {
                $brand = StorefrontBrand::query()->updateOrCreate(
                    ['slug' => $slug],
                    ['name' => $name, 'logo_path' => $logo, 'logo_background' => $background],
                );

                foreach ($members as $member) {
                    $id = $brandIds[mb_strtolower($member)] ?? null;

                    if ($id === null) {
                        continue;
                    }

                    DB::table('storefront_brand_members')->upsert(
                        [['storefront_brand_id' => $brand->id, 'brand_id' => $id]],
                        ['brand_id'],
                        ['storefront_brand_id'],
                    );
                }
            }
        });
    }
}
