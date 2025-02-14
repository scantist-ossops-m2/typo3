<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace TYPO3\CMS\Seo\MetaTag;

use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Core\Imaging\ImageManipulation\CropVariantCollection;
use TYPO3\CMS\Core\MetaTag\MetaTagManagerRegistry;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Service\ImageService;
use TYPO3\CMS\Frontend\Resource\FileCollector;

/**
 * Class to add the metatags for the SEO fields in core
 *
 * @internal
 */
#[Autoconfigure(public: true)]
readonly class MetaTagGenerator
{
    public function __construct(
        protected MetaTagManagerRegistry $metaTagManagerRegistry,
        protected ImageService $imageService
    ) {}

    /**
     * Generate the meta tags that can be set in backend and add them to frontend by using the MetaTag API
     */
    public function generate(array $params)
    {
        /** @var ServerRequestInterface $request */
        $request = $params['request'];
        $pageRecord = $request->getAttribute('frontend.page.information')->getPageRecord();
        if (!empty($pageRecord['description'])) {
            $manager = $this->metaTagManagerRegistry->getManagerForProperty('description');
            $manager->addProperty('description', $pageRecord['description']);
        }

        if (!empty($pageRecord['og_title'])) {
            $manager = $this->metaTagManagerRegistry->getManagerForProperty('og:title');
            $manager->addProperty('og:title', $pageRecord['og_title']);
        }

        if (!empty($pageRecord['og_description'])) {
            $manager = $this->metaTagManagerRegistry->getManagerForProperty('og:description');
            $manager->addProperty('og:description', $pageRecord['og_description']);
        }

        if (!empty($pageRecord['og_image'])) {
            $fileCollector = GeneralUtility::makeInstance(FileCollector::class);
            $fileCollector->addFilesFromRelation('pages', 'og_image', $pageRecord);
            $manager = $this->metaTagManagerRegistry->getManagerForProperty('og:image');

            $ogImages = $this->generateSocialImages($fileCollector->getFiles());
            foreach ($ogImages as $ogImage) {
                $subProperties = [];
                $subProperties['url'] = $ogImage['url'];
                $subProperties['width'] = $ogImage['width'];
                $subProperties['height'] = $ogImage['height'];

                if (!empty($ogImage['alternative'])) {
                    $subProperties['alt'] = $ogImage['alternative'];
                }

                $manager->addProperty(
                    'og:image',
                    $ogImage['url'],
                    $subProperties
                );
            }
        }

        $twitterCardTagRequired = false;
        if (!empty($pageRecord['twitter_title'])) {
            $twitterCardTagRequired = true;
            $manager = $this->metaTagManagerRegistry->getManagerForProperty('twitter:title');
            $manager->addProperty('twitter:title', $pageRecord['twitter_title']);
        }

        if (!empty($pageRecord['twitter_description'])) {
            $twitterCardTagRequired = true;
            $manager = $this->metaTagManagerRegistry->getManagerForProperty('twitter:description');
            $manager->addProperty('twitter:description', $pageRecord['twitter_description']);
        }

        if (!empty($pageRecord['twitter_image'])) {
            $fileCollector = GeneralUtility::makeInstance(FileCollector::class);
            $fileCollector->addFilesFromRelation('pages', 'twitter_image', $pageRecord);
            $manager = $this->metaTagManagerRegistry->getManagerForProperty('twitter:image');

            $twitterImages = $this->generateSocialImages($fileCollector->getFiles());
            foreach ($twitterImages as $twitterImage) {
                $twitterCardTagRequired = true;
                $subProperties = [];

                if (!empty($twitterImage['alternative'])) {
                    $subProperties['alt'] = $twitterImage['alternative'];
                }

                $manager->addProperty(
                    'twitter:image',
                    $twitterImage['url'],
                    $subProperties
                );
            }
        }

        $twitterCard = $pageRecord['twitter_card'] ?: ($twitterCardTagRequired ? 'summary' : '');
        if (!empty($twitterCard)) {
            $manager = $this->metaTagManagerRegistry->getManagerForProperty('twitter:card');
            $manager->addProperty('twitter:card', $twitterCard);
        }

        $noIndex = ($pageRecord['no_index']) ? 'noindex' : 'index';
        $noFollow = ($pageRecord['no_follow']) ? 'nofollow' : 'follow';

        if ($noIndex === 'noindex' || $noFollow === 'nofollow') {
            $manager = $this->metaTagManagerRegistry->getManagerForProperty('robots');
            $manager->addProperty('robots', implode(',', [$noIndex, $noFollow]));
        }
    }

    /**
     * @param list<FileReference> $fileReferences
     */
    protected function generateSocialImages(array $fileReferences): array
    {
        $socialImages = [];

        foreach ($fileReferences as $fileReference) {
            $arguments = $fileReference->getProperties();
            $image = $this->processSocialImage($fileReference);
            $socialImages[] = [
                'url' => $this->imageService->getImageUri($image, true),
                'width' => floor((float)$image->getProperty('width')),
                'height' => floor((float)$image->getProperty('height')),
                'alternative' => $arguments['alternative'],
            ];
        }

        return $socialImages;
    }

    protected function processSocialImage(FileReference $fileReference): FileInterface
    {
        $arguments = $fileReference->getProperties();
        $cropVariantCollection = CropVariantCollection::create((string)($arguments['crop'] ?? ''));
        $cropVariantName = ($arguments['cropVariant'] ?? false) ?: 'social';
        $cropArea = $cropVariantCollection->getCropArea($cropVariantName);
        $crop = $cropArea->makeAbsoluteBasedOnFile($fileReference);

        $processingConfiguration = [
            'crop' => $crop,
            'maxWidth' => 2000,
        ];

        // The image needs to be processed if:
        //  - the image width is greater than the defined maximum width, or
        //  - there is a cropping other than the full image (starts at 0,0 and has a width and height of 100%) defined
        $needsProcessing = $fileReference->getProperty('width') > $processingConfiguration['maxWidth']
            || !$cropArea->isEmpty();
        if (!$needsProcessing) {
            return $fileReference->getOriginalFile();
        }

        return $fileReference->getOriginalFile()->process(
            ProcessedFile::CONTEXT_IMAGECROPSCALEMASK,
            $processingConfiguration
        );
    }
}
