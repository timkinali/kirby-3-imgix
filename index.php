<?php
// Tamburlane custom imgix test2
use Kirby\Cms\App;
use Kirby\Cms\File;
use Kirby\Cms\FileVersion;
use Kirby\Image\Focus;

function endsWith($haystack, $needle)
{
    return substr($haystack, -strlen($needle))===$needle;
}

function imgix($url, $params = [])
{
    if (is_object($url) === true) {
        $url = $url->url();
    }

    // always convert urls to path
    $path = Url::path($url);

    // Per image option to exclude image from using imgix
    $useImgix = $params['imgix'] ?? true;

    // return the plain url if imgix is deactivated
    if (option('imgix', false) === false or option('imgix.domain', false) === false or endsWith($url, '.gif') or $useImgix === false) {
        return $url;
    }

    $defaults = option('imgix.defaults', []);

    $params  = array_merge($defaults, $params);
    $options = [];

    $map = [
        'width'   => 'w',
        'height'  => 'h',
    ];
    // kirby()->site()->log("img " . $path);
    kirby()->site()->log(dump($params, false));

    foreach ($params as $key => $value) {
        if (isset($map[$key]) && !empty($value)) {
            $options[] = $map[$key] . '=' . $value;
        } elseif (!isset($map[$key]) && !empty($value)) {
            $options[] = $key . '=' . $value;
        }
    }

    $options = implode('&', $options);

    return option('imgix.domain') . $path . '?' . $options;
}

Kirby::plugin('diesdasdigital/imgix', [
    'components' => [
        'file::version' => function (App $kirby, File $file, array $options = []) {
            static $originalComponent;

            // Per image option to exclude image from using imgix
            $useImgix = $options['imgix'] ?? true;
            
            if (option('imgix', false) !== false and $useImgix !== false) {
                
                // Support for K4 Focus
                if(isset($options['crop']) === true) {
                    // Kirby sets focus value in crop option if crop is set true
                    // isFocalPoint checks if 'crop' contains a focalpoint
                    if (Focus::isFocalPoint($options['crop']) === true) {
                        // Map keys so Imgix understands
                        [$options['fp-x'], $options['fp-y']] = Focus::parse($options['crop']);
                        $options['crop'] = 'focalpoint';
                    }
                    // If set to Imgix 'focalpoint' parameter we get the focus value from the file
                    elseif ($options['crop'] === 'focalpoint') {
                        if ($file->focus()->isNotEmpty()) {
                            [$options['fp-x'], $options['fp-y']]  = Focus::parse($file->focus());
                        } else {
                            $options['crop'] = 'center';    
                        }    
                    }
                }
                
                $url = imgix($file->mediaUrl(), $options);
                   
                return new FileVersion([
                    'modifications' => $options,
                    'original'      => $file,
                    'root'          => $file->root(),
                    'url'           => $url,
                ]);
            }

            if ($originalComponent === null) {
                $originalComponent = (require $kirby->root('kirby') . '/config/components.php')['file::version'];
            }

            return $originalComponent($kirby, $file, $options);
        },

        'file::url' => function (App $kirby, File $file): string {
            static $originalComponent;

            if (option('imgix', false) !== false) {
                if ($file->type() === 'image') {
                    return imgix($file->mediaUrl());
                }
                return $file->mediaUrl();
            }

            if ($originalComponent === null) {
                $originalComponent = (require $kirby->root('kirby') . '/config/components.php')['file::url'];
            }

            return $originalComponent($kirby, $file);
        }
    ]
]);
