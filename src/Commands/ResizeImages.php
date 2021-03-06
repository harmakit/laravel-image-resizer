<?php namespace TarunMangukiya\ImageResizer\Commands;

use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Bus\SelfHandling;
use Illuminate\Contracts\Queue\ShouldQueue;

use Intervention\Image\ImageManager as InterImage;
use TarunMangukiya\ImageResizer\ImageFile;
use TarunMangukiya\ImageResizer\Jobs\Job;

class ResizeImages extends Job implements ShouldQueue
{

    public const FIT_CROP = 0;
    public const FIT_ADD_FIELDS = 1;

    use InteractsWithQueue, SerializesModels;

	/**
	 * Image file to be resized
	 */
	protected $imageFile;

	/**
	 * Image config to be applied
	 */
	protected $type_config;

    /**
     * Intervention ImageManager Instance
     *
     * @var array
     */
    protected $interImage;

	/**
	 * Create a new command instance.
	 *
	 * @return void
	 */
	public function __construct(ImageFile $imageFile, $type_config)
	{
		$this->imageFile = $imageFile;
		$this->type_config = $type_config;
		// \Log::info('Job created ImageResizer');
	}

	/**
	 * Execute the command.
	 *
	 * @return void
	 */
	public function handle()
	{
        // Before handling the file resize
        // change the directory to public path of laravel
        // as many of the path will be used from public_path

        chdir(public_path());

        // \Log::info('ImageResizer for queue: '.$this->imageFile->fullpath);

        // Initialize InterImage Instance first
        $interConfig = config('image');
        $this->interImage = new InterImage($interConfig);

        $sizes = $this->type_config['sizes'];
        $compiled_path = $this->type_config['compiled'];
        $filename = $this->imageFile->filename;

        foreach ($sizes as $folder => $size) {
            $width = $size[0];
            $height = $size[1];
            $scaling = $size[2];
            $extension = $size[3];

            if($extension == 'original') $extension = $this->imageFile->extension;

            $is_animated = false;
            if($extension == 'gif' && $size[4] == 'animated') {
                // Check if animated is enabled for gif images
                if($this->type_config['crop']['enabled']) {
                    throw new \TarunMangukiya\ImageResizer\Exception\InvalidConfigException('Crop function along with animated gif is not allowed. Please disable crop or animated gif resize in config.');
                }
                if(!class_exists('\GifFrameExtractor\GifFrameExtractor') || !class_exists('\GifCreator\GifCreator')){
                    throw new \TarunMangukiya\ImageResizer\Exception\InvalidConfigException('You need to install "Sybio/GifFrameExtractor" and "Sybio/GifCreator" packages to resize animated gif files.');
                }
                $is_animated = \GifFrameExtractor\GifFrameExtractor::isAnimatedGif($this->imageFile->fullpath);
            }

            $target = "{$compiled_path}/{$folder}/{$filename}-{$width}x{$height}.{$extension}";

            if($is_animated){
                $this->resizeAnimatedImage($this->imageFile->fullpath, $target, $size);
            }
            else{
                // resize normal non-animated files
                $this->resizeImage($this->imageFile->fullpath, $target, $size, $folder, $this->type_config);
            }
        }

        // \Log::info('ImageResizer Resized: '.$target);

        // Free up memory
        $interConfig = null;
        $sizes = null;
        $compiled_path = null;
        $filename = null;
        $is_animated = null;
        $this->interImage = null;
    }

    /**
     * Resize Image according to the config and save it to the target
     * @param string $fullpath
     * @param string $target
     * @param array $size
     * @param string|null $size_string
     * @param array $type_config
     * @return void
     */
    public function resizeImage($fullpath, $target, $size, $size_string = null, $type_config = [])
    {
        $img = $this->interImage->make($fullpath);

        if ($size[3] === 'jpeg' || $size[3] === 'jpg') {
            $jpg = \Image::canvas($img->width(), $img->height(), '#ffffff');
            $jpg->insert($img);
            $img = $jpg;
        }

        // Reset Image Rotation before doing any activity
        $img->orientate();

        // Check if height or width is set to auto then resize must be according to the aspect ratio
        if($size[0] == null || $size[1] == null){
            $img->resize($size[0], $size[1], function ($constraint) {
                $constraint->aspectRatio();
            });
        }
        elseif ($size[2] == 'stretch') {
            // Stretch Image
            $img->resize($size[0], $size[1]);
        } else {
            // Default Fit
            if ($type_config && isset($type_config['fit_mode']) && $type_config['fit_mode'] === self::FIT_ADD_FIELDS) {
                $canvas = \Image::canvas($size[0], $size[1], '#ffffff');
                if ($img->width() / $img->height() < $canvas->width() / $canvas->height()) {
                    $img->resize(null, $canvas->height(), function ($constraint) {
                        $constraint->aspectRatio();
                    });
                } else {
                    $img->resize($canvas->width(), null, function ($constraint) {
                        $constraint->aspectRatio();
                    });
                }
                $img = $canvas->insert($img, 'center');
            } else {
                $img->fit($size[0], $size[1]);
            }
        }

        // Check if we need to add watermark to the image
        if(null !== $size_string) {
            if($this->type_config['watermark']['enabled'] && array_key_exists($size_string, $this->type_config['watermark'])) {
                $watermark = $this->type_config['watermark'][$size_string];
                $img->insert($watermark[0], $watermark[1], $watermark[2], $watermark[3]);
            }
        }

        // finally we save the image as a new file
        $img->save($target);
        $img->destroy();

        $img = null;
    }

    /**
     * Resize Animated Gif Image according to given config and save it
     * @param string $fullpath
     * @param string $target
     * @param array $size
     * @return void
     */

    public function resizeAnimatedImage($fullpath, $target, $size)
    {
        // Extract image using \GifFrameExtractor\GifFrameExtractor;

        //$gifExtractor = new \Intervention\Gif\Decoder($fullpath);
        //$decoded = $gifExtractor->decode();

        $gifFrameExtractor = new \GifFrameExtractor\GifFrameExtractor;
        $frames = $gifFrameExtractor->extract($fullpath);

        // Check if height or width is set to auto then resize must be according to the aspect ratio
        if($size[0] == null || $size[1] == null){
            // Resize each frames
            foreach ($frames as $frame) {
                $img = $this->interImage->make($frame['image']);
                $img->resize($size[0], $size[1], function ($constraint) {
                    $constraint->aspectRatio();
                });
                // $img->save(str_replace('.', uniqid().'.', $target));
                $framesProcessed[] = $img->getCore();
            }
        }
        elseif ($size[2] == 'stretch') {
            // Stretch Image
            // Resize each frames
            foreach ($frames as $frame) {
                $img = $this->interImage->make($frame['image']);
                $img->resize($size[0], $size[1]);
                $framesProcessed[] = $img->getCore();
            }
        }
        else {
            // Default Fit
            // Resize each frames
            foreach ($frames as $frame) {
                $img = $this->interImage->make($frame['image']);
                $img->fit($size[0], $size[1]);
                $framesProcessed[] = $img->getCore();
            }
        }

        $gifCreator = new \GifCreator\GifCreator;
        $gifCreator->create($framesProcessed, $gifFrameExtractor->getFrameDurations(), 0);
        $gifBinary = $gifCreator->getGif();
        $gifCreator->reset();

        \File::put($target, $gifBinary);

        // Release Memory
        $gifFrameExtractor = null;
        $gifCreator = null;
    }

}
