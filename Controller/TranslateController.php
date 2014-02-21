<?php

/*
 * Copyright 2011 Johannes M. Schmitt <schmittjoh@gmail.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace JMS\TranslationBundle\Controller;

use JMS\TranslationBundle\Exception\RuntimeException;
use JMS\TranslationBundle\Exception\InvalidArgumentException;
use JMS\TranslationBundle\Util\FileUtils;
use JMS\DiExtraBundle\Annotation as DI;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\Translation\MessageCatalogue;

/**
 * Translate Controller.
 *
 * @author Johannes M. Schmitt <schmittjoh@gmail.com>
 */
class TranslateController
{
    /** @DI\Inject */
    private $request;

    /** @DI\Inject("jms_translation.config_factory") */
    private $configFactory;

    /** @DI\Inject("jms_translation.loader_manager") */
    private $loader;

    /** @DI\Inject("service_container") */
    private $container;

    /** @DI\Inject("%jms_translation.source_language%") */
    private $sourceLanguage;

    /**
     * @Route("/", name="jms_translation_index", options = {"i18n" = false})
     * @Template
     * @param string $config
     */
    public function indexAction()
    {
        $configs = $this->configFactory->getNames();
        $config = $this->request->query->get('config') ?: reset($configs);
        if (!$config) {
            throw new RuntimeException('You need to configure at least one config under "jms_translation.configs".');
        }

        $translationsDir = $this->configFactory->getConfig($config, 'en')->getTranslationsDir();
        $files = FileUtils::findTranslationFiles($translationsDir);
        if (empty($files)) {
            throw new RuntimeException('There are no translation files for this config, please run the translation:extract command first.');
        }

        $domains = array_keys($files);
        $requestedDomain = $this->request->query->get('domain');
        $filter = $this->request->query->get('filter');
        $fileResults = array();
        
        // Here is the new case, where we have to parse all available domains properly
        // And to search for messages if exist
        if( $requestedDomain && $requestedDomain == "All" ) { // Here we are in a new specific case were we want to get all messages
            // Find all locales
            $allLocales = array();
            $locale = $this->request->query->get('locale');
        
            foreach( $files as $domain => $localeData ) {
                $tempLocales = array_keys($localeData);
                $allLocales = array_merge($allLocales,$tempLocales);

                $locales = array_keys($files[$domain]);
                natsort($locales);
                
                if( !isset($files[$domain][$locale]) && $locale ) // Locale asked but not found
                    continue;
                    
                if (!$locale) // locale not set, which means that we have to select the first available
                    $locale = reset($locales);
                    
                $data = $this->getFileDataFor($files,$domain,$locale,$locales);
                if( $data ) {
                    $fileResults[$domain] = $data;
                }
            }
            
            $locales = $allLocales;
            natsort($locales);
            
            $domain = $requestedDomain;
            array_unshift($domains , 'All');
            return array(
                'selectedConfig' => $config,
                'configs' => $configs,
                'selectedDomain' => $domain,
                'domains' => $domains,
                'selectedLocale' => $locale,
                'locales' => $locales,
                'sourceLanguage' => $this->sourceLanguage,
                'filter' => $filter,
                'files' => $fileResults,
            );
        }
        
        $domain = $this->request->query->get('domain') ?: reset($domains);
        if ((!$domain = $this->request->query->get('domain')) || !isset($files[$domain])) {
            $domain = reset($domains);
        }
        
        $locales = array_keys($files[$domain]);
        natsort($locales);
        
        if ((!$locale = $this->request->query->get('locale')) || !isset($files[$domain][$locale])) {
            $locale = reset($locales);
        }

        $data = $this->getFileDataFor($files,$domain,$locale,$locales,true);
        $fileResults = array($domain=>$data);
        array_unshift($domains , 'All');
        
        return array(
            'selectedConfig' => $config,
            'configs' => $configs,
            'selectedDomain' => $domain,
            'domains' => $domains,
            'selectedLocale' => $locale,
            'locales' => $locales,
            'sourceLanguage' => $this->sourceLanguage,
            'filter' => $filter,
            'files' => $fileResults,
        );
    }
    
    /**
     * Utility function to get data from the file and to avoid code duplication
     */
    protected function getFileDataFor($files,$domain,$locale, $locales,$force=false) {
            
        if( (!$filter = $this->request->query->get('filter')) ) {
            $filter = null;
        }
        
        $alternativeMessages = array();
        foreach ($locales as $otherLocale) {
            if ($locale === $otherLocale) {
                continue;
            }

            $altCatalogue = $this->loader->loadFile(
                $files[$domain][$otherLocale][1]->getPathName(),
                $files[$domain][$otherLocale][0],
                $otherLocale,
                $domain
            );
            foreach ($altCatalogue->getDomain($domain)->all() as $id => $message) {
                $alternativeMessages[$id][$otherLocale] = $message;
            }
        } 
        
        $catalogue = $this->loader->loadFile(
            $files[$domain][$locale][1]->getPathName(),
            $files[$domain][$locale][0],
            $locale,
            $domain
        );
        
        $newMessages = $existingMessages = array();
        foreach ($catalogue->searchDomain($domain,$filter)->all() as $id => $message) {
            if ($message->isNew()) {
                $newMessages[$id] = $message;
                continue;
            }

            $existingMessages[$id] = $message;
        }
        
        if( count($existingMessages) > 0 || count($newMessages) > 0 || $force ) {
            return array(
                'locales' => $locales,
                'domain' => $domain,
                'format' => $files[$domain][$locale][0],
                'newMessages' => $newMessages,
                'existingMessages' => $existingMessages,
                'alternativeMessages' => $alternativeMessages,
                'isWriteable' => is_writeable($files[$domain][$locale][1]),
                'file' => (string) $files[$domain][$locale][1],
            );
        }
        
        return null;
    }
}
