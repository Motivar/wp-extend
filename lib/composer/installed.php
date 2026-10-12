<?php return array(
    'root' => array(
        'name' => 'motivar/extend-wp',
        'pretty_version' => '1.5.0',
        'version' => '1.5.0.0',
        'reference' => null,
        'type' => 'wordpress-plugin',
        'install_path' => __DIR__ . '/../../',
        'aliases' => array(),
        'dev' => true,
    ),
    'versions' => array(
        'gnnpls/wp-kit' => array(
            'pretty_version' => 'dev-main',
            'version' => 'dev-main',
            'reference' => 'eab32cfd4608ec33e2ea55f489b2a172b7e5370b',
            'type' => 'library',
            'install_path' => __DIR__ . '/../gnnpls/wp-kit',
            'aliases' => array(
                0 => '9999999-dev',
            ),
            'dev_requirement' => false,
        ),
        'gnnpls/wp-self-test' => array(
            'pretty_version' => 'dev-main',
            'version' => 'dev-main',
            'reference' => '04be3dd315f483e95cf7acf3afe210f09387c4f0',
            'type' => 'library',
            'install_path' => __DIR__ . '/../gnnpls/wp-self-test',
            'aliases' => array(
                0 => '9999999-dev',
            ),
            'dev_requirement' => false,
        ),
        'motivar/extend-wp' => array(
            'pretty_version' => '1.5.0',
            'version' => '1.5.0.0',
            'reference' => null,
            'type' => 'wordpress-plugin',
            'install_path' => __DIR__ . '/../../',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
    ),
);
