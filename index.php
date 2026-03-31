<?php

// Tamburlane custom imgix
use Kirby\Cms\App as Kirby;
use Kirby\Cms\App;
use Kirby\Cms\File;
use Kirby\Cms\FileVersion;
use Kirby\Http\Url;
use Kirby\Image\Focus;
use Kirby\Toolkit\A;
use Kirby\Toolkit\Str;

function endsWith($haystack, $needle)
{
  return substr($haystack, -strlen($needle)) === $needle;
}

function imgix($file, $params = [])
{
  $url = $file->mediaUrl();

  // Per image option to exclude image from using imgix
  $useImgix = $params['imgix'] ?? true;

  // return the plain url if imgix is deactivated
  if (option('imgix', false) === false
  || option('imgix.domain', false) === false
  || endsWith($url, '.gif')
  || $useImgix === false) {
    return $url;
  }

  // always convert urls to path
  $path = Url::path($url);

  // gather options
  $defaults = option('imgix.defaults', []);
  $params = array_merge($defaults, $params);
  $params = convertFocus($file, $params);
  $options = [];

  $map = [
    'width' => 'w',
    'height' => 'h',
    'quality' => 'q'
  ];

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
// Support for K4 Focus
function convertFocus($file, $options = [])
{
  if (isset($options['crop']) === true) {

    // Kirby sets focus value in crop option if crop is set true
    // isFocalPoint checks if 'crop' contains a focalpoint
    if (Focus::isFocalPoint($options['crop']) === true) {

    // Map Kirbys focus coordinates to keys so Imgix understands
      [$options['fp-x'], $options['fp-y']] = Focus::parse($options['crop']);

      // Now set crop to Imgix focalpoint parameter
      $options['crop'] = 'focalpoint';

      if (option('debug') === true) {
        $options['fp-debug'] = 'true';
      }
    }
    // If incoming option is already set to Imgix 'focalpoint' parameter
    // we get the focus value stored in the file instead
    // need Str:contains because it can be comma separated fallbacks, however
    elseif (Str::contains($options['crop'], 'focalpoint') === true) {
      if ($file->focus()->isNotEmpty()) {
        [$options['fp-x'], $options['fp-y']] = Focus::parse($file->focus());

        if (option('debug') === true) {
          $options['fp-debug'] = 'true';
        }
      }
    }
  }
  return $options;
}

// Revert back to native Kirby options for the 'crop' option 
// since we also use it for imgix with values like 'faces' etc,
// to not generate meaningless jobs or file versions for such values (like filename-640x480-crop-faces)
// => Removes Imgix specific stuff and restores any focus set to Kirby standard
function cleanModifications($file, $options = [])
{
  if (isset($options['crop']) === true) {
    // Focalpoint -> coordinates
    if (Str::contains($options['crop'], 'focalpoint') === true) {
      $options['crop'] = $file->focus()->value() ?? 'center';
    }
    // Other imgix crop options -> center
    elseif (in_array($options['crop'], ['faces', 'entropy', 'edges'])) {
      $options['crop'] = 'center';
    }
    elseif (in_array($options['crop'], ['none', 'false'])) {
      $options['crop'] = false;
    }
  } 
  // Fix for when imgix is off and images not cropping, just resizing: 
  // Assume crop should be true when both width and height is set
  if (isset($options['crop']) === false && (isset($options['width']) && isset($options['height'])) ){
    $options['crop'] = 'center';
  }
  
  return $options;
  // Probably not needed since Kirby should ignore them anyway
  // return A::without($options, ['fit', 'facepad', 'ar', 'con', 'usm', 'duotone', 'duotone-alpha']);
}

Kirby::plugin('diesdasdigital/imgix', [
  'components' => [
    'file::version' => function (App $kirby, File $file, array $options = []) {
      static $original; //original component

      // Per image option to exclude image from using imgix
      $useImgix = $options['imgix'] ?? true;

      if (option('imgix', false) !== false && $useImgix !== false && $file->type() === 'image') {

       // Apply blueprint crop/focus options for panel images
       // Check if request path is in panel, but leave the file image view alone
        $path = $kirby->path(); 
        $isPanelImage = option('imgix.useCustomCropInPanel') === true
        && (Str::startsWith($path, 'api/') || Str::startsWith($path, 'panel/'))
        && Str::contains($path, 'files/') === false;
        
        if($isPanelImage) {
          // Merge existing options with cropoptions from blueprint
          // Note: merge so $customOptions overrides $options 
          $customOptions = $file->cropOptions();
          if(!empty($customOptions)) {
            $options = A::merge($options, $customOptions);
          } 
        }

        // Url with all Imgix specific parameters
        $url = imgix($file, $options);

        // Don't count Imgix options as modifications -- probably a good idea?
        $options = cleanModifications($file, $options); 

        return new FileVersion([
          'modifications' => $options,
          'original' => $file,
          'root' => $file->root(),
          'url' => $url,
        ]);
      }
      
      // No Imgix
      // Remove Imgix crop options passed through from $file->thumb([...])
      $options = cleanModifications($file, $options);
      $original ??= $kirby->nativeComponent('file::version');
      return $original($kirby, $file, $options);
    },

    'file::url' => function (App $kirby, File $file): string {
      static $original;

      if (option('imgix', false) !== false) {
        if ($file->type() === 'image') {
          return imgix($file);
        }
      }
      // No Imgix
      $original ??= $kirby->nativeComponent('file::url');
      return $original($kirby, $file);
    }
  ]
]);
