<?php return array(
    'root' => array(
        'name' => 'motivar/extend-wp',
        'pretty_version' => '1.5.6',
        'version' => '1.5.6.0',
        'reference' => null,
        'type' => 'wordpress-plugin',
        'install_path' => __DIR__ . '/../../',
        'aliases' => array(),
        'dev' => true,
    ),
    'versions' => array(
        'gnnpls/wp-kit' => array(
            'dev_requirement' => false,
            'replaced' => array(
                0 => '9999999-dev',
                1 => 'dev-main',
            ),
        ),
        'gnnpls/wp-self-test' => array(
            'dev_requirement' => false,
            'replaced' => array(
                0 => '9999999-dev',
                1 => 'dev-main',
            ),
        ),
        'mammothwp/wp-kit' => array(
            'pretty_version' => 'dev-main',
            'version' => 'dev-main',
            'reference' => 'df98db44126e6eda9532b7c6e19fd1df2b032b05',
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
            'reference' => 'e934368704bc977dcde31268b69bfa0dc4a4e63e',
            'type' => 'library',
            'install_path' => __DIR__ . '/../mammothwp/wp-self-test',
            'aliases' => array(
                0 => '9999999-dev',
            ),
            'dev_requirement' => false,
        ),
        'motivar/extend-wp' => array(
            'pretty_version' => '1.5.6',
            'version' => '1.5.6.0',
            'reference' => null,
            'type' => 'wordpress-plugin',
            'install_path' => __DIR__ . '/../../',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
    ),
);
