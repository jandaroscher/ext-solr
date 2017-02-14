<?php
namespace ApacheSolrForTypo3\Solr\Tests\Integration\Domain\Search\Repository;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2017 Rafael Kähm <rafael.kaehm@dkd.de>
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use ApacheSolrForTypo3\Solr\Domain\Search\Repository\ApacheSolrDocumentRepository;
use ApacheSolrForTypo3\Solr\Tests\Integration\IntegrationTest;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class ApacheSolrDocumentRepositoryTest extends IntegrationTest
{
    /**
     * @var ApacheSolrDocumentRepository
     */
    protected $apacheSolrDocumentRepository;

    public function setUp()
    {
        parent::setUp();
        // trigger a search
        $this->indexPageIdsFromFixture('can_get_apacheSolrDocuments.xml', [1, 2, 3, 4, 5]);

        $this->waitToBeVisibleInSolr();

        /* @var $apacheSolrDocumentRepository ApacheSolrDocumentRepository */
        $this->apacheSolrDocumentRepository = GeneralUtility::makeInstance(ApacheSolrDocumentRepository::class);
    }

    /**
     * Executed after each test. Emptys solr and checks if the index is empty
     */
    public function tearDown()
    {
        $this->cleanUpSolrServerAndAssertEmpty();
        parent::tearDown();
    }

    /**
     * @test
     */
    public function canFindByPageIdAndByLanguageId()
    {
        $apacheSolrDocumentsCollection = $this->apacheSolrDocumentRepository->findByPageIdAndByLanguageId(3, 0);

        $this->assertInternalType('array', $apacheSolrDocumentsCollection, 'Repository did not get Apache_Solr_Document collection from pageId 3.');
        $this->assertNotEmpty($apacheSolrDocumentsCollection, 'Repository did not get apache solr documents from pageId 3.');
        $this->assertInstanceOf(\Apache_Solr_Document::class, $apacheSolrDocumentsCollection[0], 'ApacheSolrDocumentRepository returned not an array of type Apache_Solr_Document.');
    }

    /**
     * @test
     */
    public function canReturnEmptyCollectionIfNoConnectionToSolrServerIsEstablished()
    {
        $apacheSolrDocumentsCollection = $this->apacheSolrDocumentRepository->findByPageIdAndByLanguageId(3, 777);
        $this->assertEmpty($apacheSolrDocumentsCollection, 'ApacheSolrDocumentRepository does not return empty collection if no connection to core can be established.');
    }
}
