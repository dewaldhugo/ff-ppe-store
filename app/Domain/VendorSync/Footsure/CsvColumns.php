<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Footsure;

/**
 * The columns of the Footsure daily export that the importer reads. A
 * missing or renamed column aborts the run.
 */
final class CsvColumns
{
    public const string FILE_DATE = 'File_Create_Date';

    public const string FILE_DATE_FORMAT = 'd/m/Y H:i:s';

    public const array REQUIRED = [
        'File_Create_Date', 'Product Reference', 'ProductID', 'Family Product Reference', 'Barcode',
        'Short Product Name', 'Long Product Name', 'Group Class', 'Colour Code', 'Colour Extention',
        'Size', 'UK Size', 'EU Size', 'Brand', 'Country Of Origin', 'Commodity Code',
        'Safety Standard', 'Safety Cat', 'Safety', 'Weight', 'Length', 'Width', 'Height',
        'InspirationalCopy',
        'Main Copy 1', 'Main Copy 2', 'Main Copy 3', 'Main Copy 4', 'Main Copy 5',
        'Main Copy 6', 'Main Copy 7', 'Main Copy 8', 'Main Copy 9', 'Main Copy 10',
        'Upper Outer Material', 'Lining Material', 'SoleMaterial', 'Fastening', 'Waterproof',
        'Product Status', 'Colour Status', 'Trade Price', 'SRP', 'VAT', 'Stock',
        'Image1', 'Image2', 'Image3', 'Image4', 'Image5', 'Image6', 'Image7', 'Image8',
        'Gender',
    ];
}
