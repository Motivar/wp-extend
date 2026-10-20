<?php return array(
    'root' => array(
        'name' => 'motivar/extend-wp',
        'pretty_version' => '1.5.7',
        'version' => '1.5.7.0',
        'reference' => null,
        'type' => 'wordpress-plugin',
        'install_path' => __DIR__ . '/../../',
        'aliases' => array(),
        'dev' => true,
    ),
    'versions' => array(
        'mammothwp/wp-kit' => array(
            'pretty_version' => 'dev-main',
            'version' => 'dev-main',
            'reference' => '4eba441a43b7b8fec1af6c7b3157f8e4e980a401',
            'type' => 'library',
            'install_path' => __DIR__ . '/../mammothwp/wp-kit',
            'aliases' => array(
                0 => '9999999-dev',
            ),
            'dev_requirement' => false,
        ),
        'mammothwp/wp-self-test' => array(
            'pretty_version' => 'dev-main',
            'version' => 'dev-main',
            'reference' => 'f99f6a291acdf8f5c07c8508268d84f48ddaab47',
            'type' => 'library',
            'install_path' => __DIR__ . '/../mammothwp/wp-self-test',
            'aliases' => array(
                0 => '9999999-dev',
            ),
            'dev_requirement' => false,
        ),
        'motivar/extend-wp' => array(
            'pretty_version' => '1.5.7',
            'version' => '1.5.7.0',
            'reference' => null,
            'type' => 'wordpress-plugin',
            'install_path' => __DIR__ . '/../../',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
    ),
);
