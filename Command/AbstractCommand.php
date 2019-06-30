<?php
namespace NathanDay\CatalogImagesClean\Command;

use Symfony\Component\Console\Command\Command;
use Magento\Framework\Filesystem;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Catalog\Model\Product\Media\ConfigInterface as MediaConfig;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Query\Generator;
use Magento\Framework\DB\Select;
use Magento\Framework\App\ResourceConnection;
use Magento\Catalog\Model\ResourceModel\Product\Image as ProductImage;
use Magento\Catalog\Model\ResourceModel\Product\Gallery;
use Magento\Framework\Filesystem\Driver\File;

/**
 * Class AbstractCommand
 *
 * @package NathanDay\CatalogImagesClean\Command
 */
class AbstractCommand extends Command
{
    /** Input Keys */
    const INPUT_KEY_DUPLICATE = 'duplicate';
    const INPUT_KEY_MISSING   = 'missing';
    const INPUT_KEY_UNUSED    = 'unused';

    /** @var Filesystem */
    protected $filesystem;

    /** @var WriteInterface */
    protected $mediaDirectory;

    /** @var array */
    protected $databaseImages;
    protected $missingImages;
    protected $physicalImages;
    protected $unusedImages;
    protected $duplicateImages;
    protected $physicalImageHashes;
    protected $duplicateImageTree;

    /** int */
    protected $longestFilePath;

    /** @var MediaConfig */
    protected $imageConfig;

    /** @var Generator */
    protected $batchQueryGenerator;

    /** @var ResourceConnection */
    protected $resourceConnection;

    /** @var AdapterInterface */
    protected $connection;

    /** @var int */
    protected $batchSize;

    /** @var ProductImage */
    protected $productImage;

    /** @var File */
    protected $fileDriver;

    /** @var Gallery */
    protected $gallery;

    public function __construct(
        Generator $generator,
        ResourceConnection $resourceConnection,
        Filesystem $filesystem,
        MediaConfig $imageConfig,
        ProductImage $productImage,
        File $fileDriver,
        Gallery $gallery,
        $batchSize = 100
    ) {
        parent::__construct();

        $this->filesystem = $filesystem;
        $this->batchQueryGenerator = $generator;
        $this->resourceConnection = $resourceConnection;
        $this->connection = $this->resourceConnection->getConnection();
        $this->batchSize = $batchSize;
        $this->mediaDirectory = $filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        $this->imageConfig = $imageConfig;
        $this->productImage = $productImage;
        $this->fileDriver = $fileDriver;
        $this->gallery = $gallery;
    }

    /**
     * @return array
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function getDatabaseProductImages()
    {
        if (!isset($this->databaseImages)) {
            $this->databaseImages = [];
            foreach ($this->getAllDatabaseProductImages() as $image) {
                $this->databaseImages[$image['id']] = $image['filepath'];
            }
        }

        return $this->databaseImages;
    }

    /**
     * @return int
     */
    protected function getDatabaseProductImageCount()
    {
        return $this->productImage->getCountAllProductImages();
    }

    /**
     * @return mixed
     * @throws \Magento\Framework\Exception\FileSystemException
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function getMissingProductImages()
    {
        if (!isset($this->missingImages)) {
            $this->missingImages = [];
            foreach ($this->getDatabaseProductImages() as $key => $imagePath) {
                if (!$this->doesFileExist($imagePath)) {
                    $this->missingImages[$key] = $imagePath;
                }
            }
        }

        return $this->missingImages;
    }

    /**
     * @return int
     * @throws \Magento\Framework\Exception\FileSystemException
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getMissingProductImageCount()
    {
        return count(array_unique($this->getMissingProductImages()));
    }

    /**
     * @return mixed
     * @throws \Magento\Framework\Exception\FileSystemException
     */
    protected function getPhysicalProductImages()
    {
        $mediaDirectoryPath = $this->mediaDirectory->getAbsolutePath($this->imageConfig->getBaseMediaPath());
        if (!isset($this->physicalImages)) {
            $this->physicalImages = [];
            $physicalImages = $this->fileDriver->readDirectoryRecursively($mediaDirectoryPath);

            foreach ($physicalImages as $imagePath) {
                if ($this->fileDriver->isFile($imagePath)
                    && strpos($imagePath, DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR) === false) {
                    $this->physicalImages[] = substr($imagePath, strlen($mediaDirectoryPath));
                }
            }
        }

        return $this->physicalImages;
    }

    /**
     * @return int
     * @throws \Magento\Framework\Exception\FileSystemException
     */
    protected function getPhysicalProductImageCount()
    {
        return count($this->getPhysicalProductImages());
    }

    /**
     * @return array
     * @throws \Magento\Framework\Exception\FileSystemException
     */
    protected function getPhysicalImagesHashes()
    {
        if (!isset($this->physicalImagesHash)) {
            $this->physicalImageHashes = [];

            foreach ($this->getPhysicalProductImages() as $imagePath) {
                $this->physicalImageHashes[$imagePath] = md5(
                    $this->fileDriver->fileGetContents(
                        $this->getFullImagePath($imagePath)
                    )
                );
            }

            krsort($this->physicalImageHashes);
        }

        return $this->physicalImageHashes;
    }


    protected function getDuplicateProductImages($image = null)
    {
        $physicalImageHashes = $this->getPhysicalImagesHashes();

        if ($image === null) {
            if (!isset($this->duplicateImages)) {
                $duplicateImages = array_diff_key($physicalImageHashes, array_unique($physicalImageHashes));
                array_multisort(array_keys($duplicateImages), SORT_NATURAL| SORT_FLAG_CASE, $duplicateImages);
                $this->duplicateImages = array_unique($duplicateImages);
            }

            return $this->duplicateImages;
        } else {
            if (array_key_exists($image, $physicalImageHashes)) {
                $duplicates = [];
                $duplicateKeys = array_diff(array_keys($physicalImageHashes, $physicalImageHashes[$image]), [$image]);
                foreach ($duplicateKeys as $duplicateKey) {
                    $duplicates[] = $duplicateKey;
                }

                return $duplicates;
            }

            return [];
        }
    }

    protected function getDuplicateImageCount()
    {
        return count($this->getDuplicateProductImages());
    }

    protected function getDuplicateTree($flat = false)
    {
        if (!isset($this->duplicateImageTree)) {
            $this->duplicateImageTree = [];

            foreach ($this->getDuplicateProductImages() as $image => $hash) {
                if (!$flat) {
                    $this->duplicateImageTree[$image] = $this->getDuplicateProductImages($image);
                } else {
                    $this->duplicateImageTree[$image] = implode(', ', $this->getDuplicateProductImages($image));
                }
            }
        }

        return $this->duplicateImageTree;
    }

    /**
     * @return array
     * @throws \Magento\Framework\Exception\FileSystemException
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function getUnusedProductImages()
    {
        if (!isset($this->unusedImages)) {
            $this->unusedImages = [];
            $this->unusedImages = array_diff($this->getPhysicalProductImages(), $this->getDatabaseProductImages());
        }

        return $this->unusedImages;
    }

    /**
     * @return int
     * @throws \Magento\Framework\Exception\FileSystemException
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getUnusedProductImageCount()
    {
        return count(array_unique($this->getUnusedProductImages()));
    }

    /**
     * @return \Generator
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function getAllDatabaseProductImages(): \Generator
    {
        $batchSelectIterator = $this->batchQueryGenerator->generate(
            'value_id',
            $this->getallImagesSelect(),
            $this->batchSize,
            \Magento\Framework\DB\Query\BatchIteratorInterface::NON_UNIQUE_FIELD_ITERATOR
        );

        foreach ($batchSelectIterator as $select) {
            foreach ($this->connection->fetchAll($select) as $key => $value) {
                yield $key => $value;
            }
        }
    }

    /**
     * Return Select to fetch all products images
     *
     * @return Select
     */
    protected function getAllImagesSelect(): Select
    {
        return $this->connection->select()
            ->from(
                ['images' => $this->resourceConnection->getTableName(Gallery::GALLERY_TABLE)],
                ['value_id as id', 'value as filepath']
            );
    }

    /**
     * @param $filepath
     * @return bool
     * @throws \Magento\Framework\Exception\FileSystemException
     */
    protected function doesFileExist($filepath)
    {
        return $this->fileDriver->isExists($this->getFullImagePath($filepath));
    }

    /**
     * @param $imagePath
     * @throws \Magento\Framework\Exception\FileSystemException
     */
    protected function deleteFile($imagePath)
    {
        $this->fileDriver->deleteFile($this->getFullImagePath($imagePath));
    }

    /**
     * @param array|integer $id
     */
    public function deleteGallery($id)
    {
        $this->gallery->deleteGallery($id);
    }

    /**
     * @param $filename
     * @return string
     */
    protected function getFullImagePath($filename)
    {
        return $this->mediaDirectory->getAbsolutePath(
            $this->imageConfig->getMediaPath($filename)
        );
    }

    protected function getLongestFilePath()
    {
        if (!isset($this->longestFilePath)) {
            $this->longestFilePath = max(array_map('strlen', $this->getPhysicalProductImages()));
        }

        return $this->longestFilePath;
    }
}
