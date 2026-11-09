<?php
/**
 * Critical CSS files are read from disk; a file on this site is never requested over HTTP.
 */

use EWP\DynamicAssets\Critical_CSS_File;
use EWP\DynamicAssets\Dynamic_Asset_Loader;

class Test_Critical_Css_File extends WP_UnitTestCase
{
    /**
     * HTTP requests seen by the pre_http_request filter.
     *
     * @var string[]
     */
    private $requests = array();

    /**
     * Response the pre_http_request filter answers with.
     *
     * @var array|WP_Error
     */
    private $response;

    /**
     * Files written by a test, removed in tear_down().
     *
     * @var string[]
     */
    private $files = array();

    /**
     * Intercept HTTP and reset the loader before each test.
     *
     * @return void
     */
    public function set_up()
    {
        parent::set_up();
        $this->requests = array();
        $this->response = new WP_Error('http_request_failed', 'blocked');
        add_filter('pre_http_request', array($this, 'intercept_http'), 10, 3);
        Dynamic_Asset_Loader::clear_cache();
    }

    /**
     * Remove written files and registrations after each test.
     *
     * @return void
     */
    public function tear_down()
    {
        array_map('unlink', array_filter($this->files, 'file_exists'));
        $this->files = array();
        remove_all_filters('ewp_register_dynamic_assets');
        Dynamic_Asset_Loader::clear_cache();
        parent::tear_down();
    }

    /**
     * Record an HTTP request and short-circuit it.
     *
     * @param mixed  $pre  Short-circuit value.
     * @param array  $args Request arguments.
     * @param string $url  Requested URL.
     * @return array|WP_Error The configured response.
     */
    public function intercept_http($pre, $args, $url)
    {
        $this->requests[] = $url;
        return $this->response;
    }

    /**
     * Write a file under the uploads directory (inside WP_CONTENT_DIR).
     *
     * @param string $name    File name.
     * @param string $content File content.
     * @return string Absolute path.
     */
    private function write_upload($name, $content)
    {
        $dir = wp_upload_dir()['basedir'];
        wp_mkdir_p($dir);
        $path = $dir . '/' . $name;
        file_put_contents($path, $content);
        $this->files[] = $path;
        return $path;
    }

    /**
     * URL of a file under WP_CONTENT_DIR.
     *
     * @param string $path Absolute path.
     * @return string URL.
     */
    private function content_url_of($path)
    {
        return content_url(ltrim(substr(wp_normalize_path($path), strlen(wp_normalize_path(WP_CONTENT_DIR))), '/'));
    }

    /**
     * A 200 response with a body.
     *
     * @param string $body Response body.
     * @param int    $code Status code.
     * @return array Response array.
     */
    private function http_response($body, $code = 200)
    {
        return array(
            'headers' => array(),
            'body' => $body,
            'response' => array('code' => $code, 'message' => ''),
            'cookies' => array(),
            'filename' => null,
        );
    }

    /**
     * Print the critical CSS block for the given registrations.
     *
     * @param array $assets Asset registrations.
     * @return string Printed markup.
     */
    private function critical_output($assets)
    {
        add_filter('ewp_register_dynamic_assets', function () use ($assets) {
            return $assets;
        });
        ob_start();
        Dynamic_Asset_Loader::get_instance()->add_critical_css();
        return ob_get_clean();
    }

    /**
     * A style registration.
     *
     * @param array $extra Extra keys.
     * @return array Asset registration.
     */
    private function style($extra)
    {
        return array_merge(array(
            'handle' => 'ewp-critical-test',
            'selector' => '.ewp-critical-test',
            'type' => 'style',
            'src' => content_url('uploads/full.css'),
            'minify_critical' => false,
        ), $extra);
    }

    /**
     * Url maps to path whatever the scheme or query.
     *
     * @return void
     */
    public function test_url_maps_to_path_whatever_the_scheme_or_query()
    {
        $file = new Critical_CSS_File();
        $expected = wp_normalize_path(WP_PLUGIN_DIR) . '/demo/css/critical.min.css';
        $url = plugins_url('demo/css/critical.min.css');

        $this->assertSame($expected, $file->url_to_path($url));
        $this->assertSame($expected, $file->url_to_path($url . '?ver=1.2.3#top'));
        $this->assertSame($expected, $file->url_to_path(set_url_scheme($url, 'https')));
        $this->assertSame($expected, $file->url_to_path(wp_parse_url($url, PHP_URL_PATH)));
        $this->assertSame(
            wp_normalize_path(WP_CONTENT_DIR) . '/uploads/a b.css',
            $file->url_to_path(content_url('uploads/a%20b.css'))
        );
    }

    /**
     * Url outside the content directories maps to nothing.
     *
     * @return void
     */
    public function test_url_outside_the_content_directories_maps_to_nothing()
    {
        $this->assertFalse((new Critical_CSS_File())->url_to_path(home_url('/somewhere-else/critical.css')));
    }

    /**
     * Local url detection.
     *
     * @return void
     */
    public function test_local_url_detection()
    {
        $file = new Critical_CSS_File();

        $this->assertTrue($file->is_local_url(content_url('uploads/x.css')));
        $this->assertTrue($file->is_local_url('/wp-content/uploads/x.css'));
        $this->assertFalse($file->is_local_url('https://cdn.example.org/x.css'));
    }

    /**
     * Same host url is read from disk without http.
     *
     * @return void
     */
    public function test_same_host_url_is_read_from_disk_without_http()
    {
        $path = $this->write_upload('ewp-critical.css', '.hero{color:red}');
        $css = (new Critical_CSS_File())->load(array('critical_src' => $this->content_url_of($path) . '?ver=9'));

        $this->assertSame('.hero{color:red}', $css);
        $this->assertSame(array(), $this->requests);
    }

    /**
     * Missing same host file returns false without http.
     *
     * @return void
     */
    public function test_missing_same_host_file_returns_false_without_http()
    {
        $file = new Critical_CSS_File();

        $this->assertFalse($file->load(array('critical_src' => content_url('uploads/ewp-not-there.css'))));
        $this->assertFalse($file->load(array('critical_src' => home_url('/somewhere-else/critical.css'))));
        $this->assertSame(array(), $this->requests);
    }

    /**
     * Critical path wins over critical src.
     *
     * @return void
     */
    public function test_critical_path_wins_over_critical_src()
    {
        $path = $this->write_upload('ewp-critical-path.css', '.a{top:0}');
        $css = (new Critical_CSS_File())->load(array(
            'critical_path' => $path,
            'critical_src' => 'https://cdn.example.org/x.css',
        ));

        $this->assertSame('.a{top:0}', $css);
        $this->assertSame(array(), $this->requests);
    }

    /**
     * Read local rejects non css traversal empty and streams.
     *
     * @return void
     */
    public function test_read_local_rejects_non_css_traversal_empty_and_streams()
    {
        $file = new Critical_CSS_File();
        $php = $this->write_upload('ewp-critical.php', '<?php // secret');
        $empty = $this->write_upload('ewp-critical-empty.css', "  \n");
        $css = $this->write_upload('ewp-critical-ok.css', '.b{left:0}');

        $this->assertFalse($file->read_local($php));
        $this->assertFalse($file->read_local($empty));
        $this->assertFalse($file->read_local(dirname($css) . '/sub/../ewp-critical-ok.css'));
        $this->assertFalse($file->read_local('https://cdn.example.org/x.css'));
        $this->assertSame('.b{left:0}', $file->read_local($css));
        $this->assertSame(array(), $this->requests);
    }

    /**
     * Remote file is fetched once and cached.
     *
     * @return void
     */
    public function test_remote_file_is_fetched_once_and_cached()
    {
        $this->response = $this->http_response('.c{margin:0}');
        $file = new Critical_CSS_File();
        $url = 'https://cdn.example.org/critical.css';

        $this->assertSame('.c{margin:0}', $file->load(array('critical_src' => $url)));
        $this->assertSame('.c{margin:0}', $file->load(array('critical_src' => $url)));
        $this->assertCount(1, $this->requests);
    }

    /**
     * Remote failure is cached too.
     *
     * @return void
     */
    public function test_remote_failure_is_cached_too()
    {
        $file = new Critical_CSS_File();
        $url = 'https://cdn.example.org/down.css';

        $this->assertFalse($file->fetch_remote($url));
        $this->assertFalse($file->fetch_remote($url));
        $this->assertCount(1, $this->requests);
    }

    /**
     * Remote error page and style breakout are rejected.
     *
     * @return void
     */
    public function test_remote_error_page_and_style_breakout_are_rejected()
    {
        $file = new Critical_CSS_File();

        $this->response = $this->http_response('<html>Not found</html>', 404);
        $this->assertFalse($file->fetch_remote('https://cdn.example.org/404.css'));

        $this->response = $this->http_response('.d{}</style><script>alert(1)</script>');
        $this->assertFalse($file->fetch_remote('https://cdn.example.org/evil.css'));
    }

    /**
     * Loader inlines the file and skips a missing one.
     *
     * @return void
     */
    public function test_loader_inlines_the_file_and_skips_a_missing_one()
    {
        $path = $this->write_upload('ewp-critical-loader.css', '.hero{color:blue}');
        $output = $this->critical_output(array(
            $this->style(array('handle' => 'ewp-has-file', 'critical_path' => $path)),
            $this->style(array('handle' => 'ewp-no-file', 'critical_src' => content_url('uploads/ewp-not-there.css'))),
        ));

        $this->assertStringContainsString('.hero{color:blue}', $output);
        $this->assertStringContainsString('data-assets="ewp-has-file"', $output);
        $this->assertSame(array(), $this->requests);
    }

    /**
     * Loader prefers inline critical css over a missing file.
     *
     * @return void
     */
    public function test_loader_prefers_inline_critical_css_over_a_missing_file()
    {
        $output = $this->critical_output(array(
            $this->style(array(
                'critical_css' => '.inline{color:green}',
                'critical_src' => content_url('uploads/ewp-not-there.css'),
            )),
        ));

        $this->assertStringContainsString('.inline{color:green}', $output);
        $this->assertSame(array(), $this->requests);
    }

    /**
     * Critical path is not sent to the browser.
     *
     * @return void
     */
    public function test_critical_path_is_not_sent_to_the_browser()
    {
        $path = $this->write_upload('ewp-critical-private.css', '.p{color:red}');
        add_filter('ewp_register_dynamic_assets', function () use ($path) {
            return array($this->style(array('critical_path' => $path)));
        });

        $loader = Dynamic_Asset_Loader::get_instance();
        $loader->register_scripts();
        $loader->enqueue_loader();
        $data = (string) wp_scripts()->get_data('ewp-dynamic-asset-loader', 'data');

        $this->assertStringContainsString('ewp-critical-test', $data);
        $this->assertStringNotContainsString('ewp-critical-private.css', $data);
    }
}
