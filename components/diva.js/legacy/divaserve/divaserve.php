<?php
# Copyright (C) 2011, 2012 by Wendy Liu, Andrew Hankinson, Laurent Pugin
# 
# Permission is hereby granted, free of charge, to any person obtaining a copy
# of this software and associated documentation files (the "Software"), to deal
# in the Software without restriction, including without limitation the rights
# to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
# copies of the Software, and to permit persons to whom the Software is
# furnished to do so, subject to the following conditions:
# 
# The above copyright notice and this permission notice shall be included in
# all copies or substantial portions of the Software.
# 
# THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
# IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
# FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
# AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
# LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
# OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
# THE SOFTWARE.

header("Content-type: application/json");

// Image directory. Images in this directory will be served by folder name,
// so if you have a folder named ...images/old_manuscript, a request for 
// divaserve.php?d=old_manuscript will use the images in that directory.
$IMAGE_DIR = "/mnt/images";
$CACHE_DIR = "/tmp/diva.js";

// only useful if you have memcache installed. 
$MEMCACHE_SERVER = "127.0.0.1";
$MEMCACHE_PORT = 11211;

// Nothing below this line should need to be changed
// -------------------------------------------------

function get_max_zoom_level($img_wid, $img_hei, $t_wid, $t_hei) {
    $largest_dim = ($img_wid > $img_hei) ? $img_wid : $img_hei;
    $t_dim = ($img_wid > $img_hei) ? $t_wid : $t_hei;

    $zoom_levels = ceil(log(($largest_dim + 1)/($t_dim + 1), 2));
    return intval($zoom_levels);
}

function incorporate_zoom($img_dim, $zoom_difference) {
    return $img_dim / pow(2, $zoom_difference);
}

function check_memcache() {
    global $MEMCACHE_SERVER, $MEMCACHE_PORT;
    if (extension_loaded('memcached')) {
    $m = new Memcached();
    $avail = $m->addServer($MEMCACHE_SERVER, $MEMCACHE_PORT);
        if ($avail) {
             return TRUE;
    } else {
         return FALSE;
    }
    } else {
    return FALSE;
    }
}

$MEMCACHE_AVAILABLE = check_memcache();
if($MEMCACHE_AVAILABLE) {
    $MEMCONN = new Memcached();
    $MEMCONN->addServer($MEMCACHE_SERVER, $MEMCACHE_PORT);
}

if (!isset($_GET['d'])) {
    die("Missing 'd' param (image directory)");
}

$dir = preg_replace('/[^-a-zA-Z0-9_]/', '', $_GET['d']);

$til_wid_get = (isset($_GET['w'])) ? intval($_GET['w']) : 0;
$til_hei_get = (isset($_GET['h'])) ? intval($_GET['h']) : 0;
$til_wid = ($til_wid_get > 0) ? $til_wid_get : 256;
$til_hei = ($til_hei_get > 0) ? $til_hei_get : 256;

$cache_file = $CACHE_DIR . "/" . $dir . ".json";
$img_dir = $IMAGE_DIR . "/" . $dir;

if ($MEMCACHE_AVAILABLE) {
     $cachekey = $dir;
}

$pgs = array();

if (!file_exists($CACHE_DIR)) {
    // Create the diva.js/ directory under /tmp/
    mkdir($img_cache, 0755);
}

// If the cache file does not exist, compute all the data
if (!file_exists($cache_file)) {
    $images = array();
    $lowest_max_zoom = 0;

    $i = 0;
    foreach (glob($img_dir . '/*') as $img_file) {
        $img_size = false;

        $img_size = getimagesize($img_file);

        /* 
            Since we're fairly liberal with the files we pull in,
            and we don't just want to go on image extensions
            we need to check if it's actually an image.
        */
        if(!$img_size) {
            continue;
        }

        $img_wid = $img_size[0];
        $img_hei = $img_size[1];

        $max_zoom = get_max_zoom_level($img_wid, $img_hei, $til_wid, $til_hei);
        $lowest_max_zoom = ($lowest_max_zoom > $max_zoom || $lowest_max_zoom == 0) ? $max_zoom : $lowest_max_zoom;

        // Figure out the image filename
        $img_fn = substr($img_file, strrpos($img_file, '/') + 1);

        $images[$i] = array(
            'mx_h'      => $img_hei,
            'mx_w'      => $img_wid,
            'mx_z'      => $max_zoom,
            'fn'        => $img_fn,
        );

        $i++;
    }

    // Now go through them again, store in $pgs
    // We do it again so we don't send unnecessary data (for zooms greater than lowest_max_zoom)
    $num_pages = $max_ratio = $min_ratio = 0;
    $t_wid = array_fill(0, $lowest_max_zoom + 1, 0);
    $t_hei = array_fill(0, $lowest_max_zoom + 1, 0);
    $mx_h = array_fill(0, $lowest_max_zoom + 1, 0);
    $mx_w = array_fill(0, $lowest_max_zoom + 1, 0);
    $a_wid = array();
    $a_hei = array();

    for ($i = 0; $i < count($images); $i++) {
        if (array_key_exists($i, $images)) {
            $page_data = array();

            // Get data for all zoom levels
            for ($j = 0; $j <= $lowest_max_zoom; $j++) {
                $h = incorporate_zoom($images[$i]['mx_h'], $lowest_max_zoom - $j);
                $w = incorporate_zoom($images[$i]['mx_w'], $lowest_max_zoom - $j);
                $c = ceil($w / $til_wid);
                $r = ceil($h / $til_wid);
                $page_data[] = array(
                    'c'     => $c,
                    'r'     => $r,
                    'h'     => $h,
                    'w'     => $w,
                );

                $t_wid[$j] += $w;
                $t_hei[$j] += $h;

                $mx_h[$j] = ($h > $mx_h[$j]) ? $h : $mx_h[$j];
                $mx_w[$j] = ($w > $mx_w[$j]) ? $w : $mx_w[$j];
            }

            $m_z = $images[$i]['mx_z'];
            $fn = $images[$i]['fn'];
            $pgs[] = array(
                'd'     => $page_data,
                'm'   => $m_z,
                'f'    => $fn,
            );

            $ratio = $h / $w;
            $max_ratio = ($ratio > $max_ratio) ? $ratio : $max_ratio;
            $min_ratio = ($ratio < $min_ratio || $min_ratio == 0) ? $ratio : $min_ratio;
            $num_pages++;
        }
    }

    // Calculate and store the average widths
    for ($j = 0; $j <= $lowest_max_zoom; $j++) {
        $a_wid[] = $t_wid[$j] / $num_pages;
        $a_hei[] = $t_hei[$j] / $num_pages;
    }

    // Setup the dimensions array
    $dims = array(
        'a_wid'         => $a_wid,
        'a_hei'         => $a_hei,
        'max_w'         => $mx_w,
        'max_h'         => $mx_h,
        'max_ratio'     => $max_ratio,
        'min_ratio'     => $min_ratio,
        't_hei'         => $t_hei,
        't_wid'         => $t_wid
    );

    // Get the title by replacing hyphens, underscores with spaces and title-casing it
    $title = str_replace('-', ' ', $dir);
    $title = str_replace('_', ' ', $dir);
    $title = ucwords($title);

    // The full array to be returned
    $data = array(
        'item_title'    => $title,
        'dims'          => $dims,
        'max_zoom'      => $lowest_max_zoom,
        'pgs'           => $pgs
    );

    $json = json_encode($data);

    // Save it to a text file in the cache directory
    file_put_contents($cache_file, $json);

    if ($MEMCACHE_AVAILABLE) {
        $MEMCONN->set($cachekey, $json);
    }

    echo $json;
} else {
    if ($MEMCACHE_AVAILABLE) {
        if (!($json = $MEMCONN->get($cachekey))) {
            if ($MEMCONN->getResultCode() == Memcached::RES_NOTFOUND) {
                $json = file_get_contents($cache_file);
                $MEMCONN->set($cachekey, $json);
            }
        }
    } else {
        $json = file_get_contents($cache_file);
    }

    echo $json;
}

?>
