<?php

$sMetadataVersion = '2.1';
$aModule = [
    'id' => 'marm/oxid-api',
    'title' => 'marmalade :: Oxid API',
    'description' => 'Simple API for OXID',
    'thumbnail'   => 'marmalade.jpg',
    'version'     => '1.0',
    'author'      => 'marmalade GmbH',
    'url'         => 'https://www.marmalade.de',
    'email'       => 'support@marmalade.de',
    'controllers' => [
        'marm_oxid_api' => \Marmalade\OxidApi\Controller\OxidApi::class
    ]
];