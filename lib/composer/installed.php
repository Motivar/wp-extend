<?php return array(
    'root' => array(
        'name' => 'motivar/extend-wp',
        'pretty_version' => '1.7.0',
        'version' => '1.7.0.0',
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
            'reference' => '24ce69ce66bc756d93fdcc74d1022f6553c6173d',
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
            'reference' => '8becc52218bc602d913463a08a583f7af55b1643',
            'type' => 'library',
            'install_path' => __DIR__ . '/../mammothwp/wp-self-test',
            'aliases' => array(
                0 => '9999999-dev',
            ),
            'dev_requirement' => false,
        ),
        'motivar/extend-wp' => array(
            'pretty_version' => '1.7.0',
            'version' => '1.7.0.0',
            'reference' => null,
            'type' => 'wordpress-plugin',
            'install_path' => __DIR__ . '/../../',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
    ),
);
