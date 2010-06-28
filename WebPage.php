<?php

class WebPage
{
    protected $page_url;
    protected $base_url;
    protected $content;


    /**
     * void __construct ([string $url = NULL[, bool $make_absolute = FALSE])
     */
    public function __construct($url = NULL, $make_absolute = FALSE)
    {
        if ($url && ($url != 'http://')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_HEADER, 0);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_SSLVERSION, 3);
            $this->content = curl_exec($ch);
            curl_close($ch);

            $this->page_url = $url;

            if (preg_match('#<base href="(.*?)"(?: /)?>#ims',
                            $this->content, $matches) > 0) {
                $this->base_url = $matches[1];
            } else {
                $this->base_url = $this->page_url;
            }

            if ($make_absolute) {

                // do the replacement
                $this->content = preg_replace(
                    '#(href|src|action)="([^"]*)"#imse',
                    "'$1=\"' . WebPage::rel2abs('$2','$this->base_url') . '\"'",
                    $this->content);

            }
        }
    }


    /*
     * string rel2abs(string $rel, string $base)
     *
     * Converts relative URLs to absolute.
     *
     * taken from http://nashruddin.com/PHP_Script_for_Converting_Relative_to_Absolute_URL
     */
    protected static function rel2abs($rel, $base)
    {
        /* return if already absolute URL */
        if (parse_url($rel, PHP_URL_SCHEME) != '') return $rel;

        /* queries and anchors */
        if ($rel[0]=='#' || $rel[0]=='?') return $base.$rel;

        /* parse base URL and convert to local variables:
           $scheme, $host, $path */
        extract(parse_url($base));

        /* remove non-directory element from path */
        $path = preg_replace('#/[^/]*$#', '', $path);

        /* destroy path if relative url points to root */
        if ($rel[0] == '/') $path = '';

        /* dirty absolute URL */
        $abs = "$host$path/$rel";

        /* replace '//' or '/./' or '/foo/../' with '/' */
        $re = array('#(/\.?/)#', '#/(?!\.\.)[^/]+/\.\./#');
        for($n=1; $n>0; $abs=preg_replace($re, '/', $abs, -1, $n)) {}

        /* absolute URL is ready! */
        return $scheme.'://'.$abs;
    }

    /**
     * string url()
     *
     * Returns page URL.
     */
    public function url()
    {
        return $this->page_url;
    }


    /**
     * string base()
     *
     * Returns base URL.
     */
    public function base()
    {
        return $this->base_url;
    }


    /**
     * string page()
     *
     * Returns page content.
     */
    public function page()
    {
        return $this->content;
    }


    /**
     * string html()
     *
     * Returns <html></html>, <html><EOF> or entire content
     */
    public function html()
    {
        if (preg_match('#<html[^>]*>(.*)</html>#ims', $this->content, $matches) > 0) {
            return $matches[1];
        } elseif (preg_match('#<html[^>]*>(.*)#ims', $this->content, $matches) > 0) {
            return $matches[1];
        } else {
            return $this->content;
        }
    }


    /**
     * string head()
     *
     * Returns <head></head>
     */
    public function head()
    {
        if (preg_match('#<head[^>]*>(.*)</head>#ims', $this->content, $matches) > 0) {
            return $matches[1];
        } else {
            return NULL;
        }
    }


    /**
     * string body()
     *
     * Returns <body></body>, <body><EOF> or entire document
     */
    public function body()
    {
        if (preg_match('#<body[^>]*>(.*)</body>#ims', $this->content, $matches) > 0) {
            return $matches[1];
        } elseif (preg_match('#<body[^>]*>(.*)#ims', $this->content, $matches) > 0) {
            return $matches[1];
        } else {
            return $this->content;
        }
    }


    /**
     * mixed tidy(string $tidyopts, [bool $inplace = FALSE])
     *
     * Tidies up received page. If $inplace, then the tidied-up
     * page gets written back to $this->content, else it gets returned.
     */
    public function tidy($tidyopts, $inplace = FALSE)
    {
        // most of this code is copied from http://wiki.dreamhost.com/Installing_Tidy
        $descriptorspec = array(
            0 => array("pipe", "r"), // stdin is a pipe that the child will read from
            1 => array("pipe", "w"), // stdout is a pipe that the child will write to
            2 => array("pipe", "r") // stderr
        );

        $process = proc_open(
            "tidy -m $tidyopts", $descriptorspec, $pipes
        );

        if (is_resource($process)) {

            // $pipes now looks like this:
            // 0 => writeable handle connected to child stdin
            // 1 => readable handle connected to child stdout
            // 2 => stderr pipe

            // writes the bad html to the tidy process that is reading from stdin.
            fwrite($pipes[0], $this->content);
            fclose($pipes[0]);

            // reads the good html from the tidy process that is writing to stdout.
            $good_html = stream_get_contents($pipes[1]);
            fclose($pipes[1]);

            // don't care about the stderr, but you might.

            // It is important that you close any pipes before calling
            // proc_close in order to avoid a deadlock
            $return_value = proc_close($process);

            // take the parsed html and return it or update $this->content
            if ($inplace) {
                $this->content = $good_html;
                return TRUE;
            } else {
                return $good_html;
            }

        }

    }

}

?>
