<?php

declare(strict_types=1);

return [

    /*
     * The "Shop by standard" chips on the home page. Each links to the
     * safety-footwear listing filtered by one facet value. `facet` is a
     * ListingQuery facet (safety_class or features). The label is the
     * feature's own label unless `label` is set here.
     *
     * Before launch, check on the real catalogue that every chip returns
     * products (see the Launch checklist in CLAUDE.md).
     */
    'standards' => [
        ['facet' => 'safety_class', 'value' => 'S1P'],
        ['facet' => 'safety_class', 'value' => 'S3'],
        ['facet' => 'features', 'value' => 'ESD', 'label' => 'ESD'],
        ['facet' => 'features', 'value' => 'METAL_FREE'],
        ['facet' => 'features', 'value' => 'WR', 'label' => 'Waterproof'],
    ],

    /*
     * The trust strip under the industries slider. Each slot is one short claim. An empty
     * slot is not rendered. The fourth slot is for a delivery claim that the
     * client has not confirmed yet: leave it empty until they do (see the
     * Launch checklist in CLAUDE.md).
     */
    'trust' => [
        'Prices exclusive of VAT',
        'Safety footwear to EN ISO 20345',
        'Wide range of sizes',
        '',
    ],

];
