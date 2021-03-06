<?php

/**
 * @file tests/plugins/generic/lucene/classes/SolrWebServiceTest.inc.php
 *
 * Copyright (c) 2000-2012 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class SolrWebServiceTest
 * @ingroup tests_plugins_generic_lucene_classes
 * @see SolrWebService
 *
 * @brief Test class for the SolrWebService class
 */


require_mock_env('env2'); // Make sure we're in an en_US environment by using the mock AppLocale.

import('lib.pkp.tests.PKPTestCase');
import('plugins.generic.lucene.classes.SolrWebService');
import('plugins.generic.lucene.classes.EmbeddedServer');
import('classes.article.PublishedArticle');
import('classes.journal.Journal');
import('classes.core.PageRouter');

class SolrWebServiceTest extends PKPTestCase {

	/** @var SolrWebService */
	private $solrWebService;


	//
	// Implementing protected template methods from PKPTestCase
	//
	/**
	 * @see PKPTestCase::getMockedDAOs()
	 */
	protected function getMockedDAOs() {
		$mockedDaos = parent::getMockedDAOs();
		$mockedDaos += array(
			'AuthorDAO', 'IssueDAO', 'SuppFileDAO', 'ArticleGalleyDAO'
		);
		return $mockedDaos;
	}

	/**
	 * @see PKPTestCase::getMockedRegistryKeys()
	 */
	protected function getMockedRegistryKeys() {
		return array('request');
	}

	/**
	 * @see PKPTestCase::setUp()
	 */
	protected function setUp() {
		parent::setUp();

		// Instantiate our web service for testing.
		$this->solrWebService = new SolrWebService('http://localhost:8983/solr/ojs/search', 'admin', 'please change', 'test-inst');
	}


	//
	// Unit tests
	//
	/**
	 * @covers SolrWebService
	 *
	 * NB: Depends on correct journal indexing
	 * and must therefore be run after testIndexJournal().
	 * We run journal indexing as the last test and
	 * this test as the first test as journal indexing
	 * is asynchronous. This means that a prior test
	 * run must be successful for this test to pass.
	 */
	public function testRetrieveResults() {
		$embeddedServer = new EmbeddedServer();
		$this->_startServer($embeddedServer);

		// Make sure that the journal is properly indexed.
		$this->_indexTestJournals();

		// Make a search on specific fields.
		$searchRequest = new SolrSearchRequest();
		$journal = new Journal();
		$journal->setId(2);
		$searchRequest->setJournal($journal);
		$searchRequest->setQuery(
			array(
				'suppFiles' => 'pizza',
				'authors' => 'Author',
				'galleyFullText' => 'Nutella',
				'title' => 'Article'
			)
		);
		$searchRequest->setFromDate(date('Y-m-d\TH:i:s\Z', strtotime('2000-01-01')));
		$totalResults = null;
		$scoredResults = $this->solrWebService->retrieveResults($searchRequest, $totalResults);
		self::assertTrue(is_int($totalResults), $totalResults > 0);
		self::assertTrue(is_array($scoredResults));
		self::assertTrue(!empty($scoredResults));
		self::assertTrue(in_array('3', $scoredResults));

		// Test result set ordering via simple (default field) search.
		$searchRequest->setQuery(array('title' => 'lucene'));
		$searchRequest->setOrderBy('authors');
		$searchRequest->setOrderDir('asc');
		$scoredResults = $this->solrWebService->retrieveResults($searchRequest, $totalResults);
		self::assertEquals(array(4, 3), array_values($scoredResults));
		$searchRequest->setOrderBy('title');
		$searchRequest->setOrderDir('desc');
		$scoredResults = $this->solrWebService->retrieveResults($searchRequest, $totalResults);
		self::assertEquals(array(3, 4), array_values($scoredResults));
	}

	/**
	 * @covers SolrWebService
	 */
	public function testGetAvailableFields() {
		$embeddedServer = new EmbeddedServer();
		$this->_startServer($embeddedServer);
		$this->solrWebService->flushFieldCache();
		// Only a few exemplary keys to make sure that we got something useful back.
		$searchFields = $this->solrWebService->getAvailableFields('search');
		foreach(array('authors', 'title', 'galleyFullText') as $fieldName) {
			self::assertArrayHasKey($fieldName, $searchFields, "The search field $fieldName should exist.");
			self::assertNotEmpty($searchFields[$fieldName], "The search field $fieldName should not be empty.");
		}
		$sortFields = $this->solrWebService->getAvailableFields('sort');
		foreach(array('authors', 'issuePublicationDate') as $fieldName) {
			self::assertArrayHasKey($fieldName, $sortFields, "The sort field $fieldName should exist.");
			self::assertNotEmpty($sortFields[$fieldName], "The sort field $fieldName should not be empty.");
		}
	}

	/**
	 * @covers SolrWebService
	 */
	public function testGetServerStatus() {
		// Make sure the server has been started.
		$embeddedServer = new EmbeddedServer();
		$result = $this->_startServer($embeddedServer);

		// Test the status message.
		self::assertEquals(SOLR_STATUS_ONLINE, $result['status']);
		self::assertEquals('##plugins.generic.lucene.message.indexOnline##', $result['message']);

		// Stop the server, then test the status again.
		$embeddedServer->stop();
		while($embeddedServer->isRunning()) sleep(1);
		self::assertEquals(SOLR_STATUS_OFFLINE, $this->solrWebService->getServerStatus());
		self::assertEquals('##plugins.generic.lucene.error.searchServiceOffline##', $this->solrWebService->getServiceMessage());

		// Restart the server.
		$result = $this->_startServer($embeddedServer);
	}

	/**
	 * @covers SolrWebService
	 */
	public function testGetArticleXml() {
		// Generate test objects.
		$article = $this->_getTestArticle();
		$journal = $this->_getTestJournal();

		// Test the transfer XML file.
		$articleDoc = $this->solrWebService->_getArticleXml($article, $journal);
		self::assertXmlStringEqualsXmlFile(
			'tests/plugins/generic/lucene/classes/test-article.xml',
			XMLCustomWriter::getXml($articleDoc)
		);
	}

	/**
	 * @covers SolrWebService
	 */
	public function testDeleteArticleFromIndex() {
		self::assertTrue($this->solrWebService->deleteArticleFromIndex(3));
	}

	/**
	 * @covers SolrWebService
	 */
	public function testIndexArticle() {
		// Generate test objects.
		$article = $this->_getTestArticle();
		$journal = $this->_getTestJournal();

		// Test indexing. The service returns true if the article
		// was successfully processed.
		self::assertTrue($this->solrWebService->indexArticle($article, $journal));
	}

	/**
	 * @covers SolrWebService
	 */
	public function testDeleteAllArticlesFromIndex() {
		self::assertTrue($this->solrWebService->deleteAllArticlesFromIndex());

		// Rebuild the index.
		$this->_indexTestJournals();
	}


	//
	// Private helper methods
	//
	/**
	 * Start the embedded server.
	 * @param $embeddedServer EmbeddedServer
	 * @return $result
	 */
	private function _startServer($embeddedServer) {
		if (!$embeddedServer->isRunning()) {
			$embeddedServer->start();
		}
		do {
			sleep(1);
			$result = $this->solrWebService->getServerStatus();
		} while ($result != SOLR_STATUS_ONLINE);
		return array(
			'status' => $result,
			'message' => $this->solrWebService->getServiceMessage()
		);
	}

	/**
	 * Mock and register a ArticleGalleyDAO as a test
	 * back end for the SolrWebService class.
	 */
	private function _registerMockArticleGalleyDAO() {
		// Mock an ArticleGalleyDAO.
		$galleyDao = $this->getMock('ArticleGalleyDAO', array('getGalleysByArticle'), array(), '', false);

		// Mock a list of supplementary files.
		$galley1 = new ArticleGalley();
		$galley1->setId(4);
		$galley1->setLocale('de_DE');
		$galley1->setFileType('application/pdf');
		$galley1->setFileName('galley1.pdf');
		$galley2 = new ArticleGalley();
		$galley2->setId(5);
		$galley2->setLocale('en_US');
		$galley2->setFileType('text/html');
		$galley2->setFileName('galley2.html');
		$galleys = array($galley1, $galley2);

		// Mock the getGalleysByArticle() method.
		$galleyDao->expects($this->any())
		          ->method('getGalleysByArticle')
		          ->will($this->returnValue($galleys));

		// Register the mock DAO.
		DAORegistry::registerDAO('ArticleGalleyDAO', $galleyDao);
	}

	/**
	 * Mock and register a SuppFileDAO as a test
	 * back end for the SolrWebService class.
	 */
	private function _registerMockSuppFileDAO() {
		// Mock an SuppFileDAO.
		$suppFileDao = $this->getMock('SuppFileDAO', array('getSuppFilesByArticle'), array(), '', false);

		// Mock a list of supplementary files.
		$suppFile1 = new SuppFile();
		$suppFile1->setId(2);
		$suppFile1->setLanguage('de');
		$suppFile1->setFileType('application/pdf');
		$suppFile1->setFileName('suppFile1.pdf');
		$suppFile2 = new SuppFile();
		$suppFile2->setId(3);
		$suppFile2->setLanguage('tu');
		$suppFile2->setFileType('text/html');
		$suppFile2->setFileName('suppFile2.html');
		$suppFile2->setTitle('Titel', 'de_DE');
		$suppFile2->setCreator('Autor', 'de_DE');
		$suppFile2->setSubject('Thema', 'de_DE');
		$suppFile2->setTypeOther('Sonstiger Typ', 'de_DE');
		$suppFile2->setDescription('Beschreibung', 'de_DE');
		$suppFile2->setSource('Quelle', 'de_DE');
		$suppFiles = array($suppFile1, $suppFile2);

		// Mock the getSuppFilesByArticle() method.
		$suppFileDao->expects($this->any())
		            ->method('getSuppFilesByArticle')
		            ->will($this->returnValue($suppFiles));

		// Register the mock DAO.
		DAORegistry::registerDAO('SuppFileDAO', $suppFileDao);
	}

	/**
	 * Mock and register an AuthorDAO as a test
	 * back end for the SolrWebService class.
	 */
	private function _registerMockAuthorDAO() {
		// Mock an AuthorDAO.
		$authorDao = $this->getMock('AuthorDAO', array('getAuthorsBySubmissionId'), array(), '', false);

		// Mock a list of authors.
		$author1 = new Author();
		$author1->setFirstName('First');
		$author1->setLastName('Author');
		$author2 = new Author();
		$author2->setFirstName('Second');
		$author2->setMiddleName('M.');
		$author2->setLastName('Name');
		$authors = array($author1, $author2);

		// Mock the getAuthorsBySubmissionId() method.
		$authorDao->expects($this->any())
		          ->method('getAuthorsBySubmissionId')
		          ->will($this->returnValue($authors));

		// Register the mock DAO.
		DAORegistry::registerDAO('AuthorDAO', $authorDao);
	}

	/**
	 * Mock and register an IssueDAO as a test
	 * back end for the SolrWebService class.
	 */
	private function _registerMockIssueDAO() {
		// Mock an IssueDAO.
		$issueDao = $this->getMock('IssueDAO', array('getIssueById'), array(), '', false);

		// Mock an issue.
		$issue = new Issue();
		$issue->setDatePublished('2012-03-15 15:30:00');

		// Mock the getIssueById() method.
		$issueDao->expects($this->any())
		         ->method('getIssueById')
		         ->will($this->returnValue($issue));

		// Register the mock DAO.
		DAORegistry::registerDAO('IssueDAO', $issueDao);
	}

	/**
	 * Activate mock DAOs for authors, galleys and supp files
	 * and return a test article.
	 *
	 * @return Article
	 */
	private function _getTestArticle() {
		// Activate the mock DAOs.
		$this->_registerMockAuthorDAO();
		$this->_registerMockIssueDAO();
		$this->_registerMockArticleGalleyDAO();
		$this->_registerMockSuppFileDAO();

		// We need a router for URL generation.
		$application =& PKPApplication::getApplication();
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$request =& $application->getRequest();
		$router = new PageRouter();
		$router->setApplication($application);
		$request->setRouter($router);

		// Create a test article.
		$article = new PublishedArticle();
		$article->setId(3);
		$article->setJournalId(2);
		$article->setIssueId(1);
		$article->setTitle('Deutscher Titel', 'de_DE');
		$article->setTitle('English Title', 'en_US');
		$article->setAbstract('Deutsche Zusammenfassung', 'de_DE');
		$article->setAbstract('English Abstract', 'en_US');
		$article->setDiscipline('Sozialwissenschaften', 'de_DE');
		$article->setDiscipline('Social Sciences', 'en_US');
		$article->setSubject('Thema', 'de_DE');
		$article->setSubjectClass('Ein Themengebiet', 'de_DE');
		$article->setSubject('subject', 'en_US');
		$article->setSubjectClass('Uma classe de temas', 'pt_BR');
		$article->setType('Typ', 'de_DE');
		$article->setType('type', 'en_US');
		$article->setCoverageGeo('Kaltes Kap', 'de_DE');
		$article->setCoverageGeo('Cabo Frio', 'pt_BR');
		$article->setCoverageChron('Sommer 2012', 'de_DE');
		$article->setCoverageChron('Summer 2012', 'en_US');
		$article->setCoverageSample('Alles', 'de_DE');
		$article->setCoverageSample('everything', 'en_US');
		$article->setDatePublished('2012-03-15 16:45:00');
		$article->setLocale('de_DE');
		return $article;
	}

	/**
	 * Return a test journal.
	 *
	 * @return Journal
	 */
	private function _getTestJournal() {
		// Generate a test journal.
		$journal = $this->getMock('Journal', array('getSetting'));
		$journal->setId('2');
		$journal->setPath('lucene-test');
		$journal->setData(
			'supportedLocales',
			array(
				'en_US' => 'English',
				'de_DE' => 'German',
				'fr_FR' => 'French'
			)
		);
		$journal->expects($this->any())
		        ->method('getSetting')
		        ->will($this->returnCallback(array($this, 'journalGetSettingCallback')));
		return $journal;
	}

	/**
	 * A callback mocking the Journal::getSetting() method.
	 * @param $name string
	 * @param $locale string
	 * @return string
	 */
	public function &journalGetSettingCallback($name, $locale) {
		$titleValues = array(
			'de_DE' => 'Zeitschrift',
			'en_US' => 'Journal'
		);
		if ($name == 'title' && isset($titleValues[$locale])) {
			return $titleValues[$locale];
		}
		$nullVar = null;
		return $nullVar;
	}

	/**
	 * Index the test journal (and test that this actually works).
	 */
	private function _indexTestJournals() {
		// We need a router for URL generation.
		$application =& PKPApplication::getApplication();
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$request =& $application->getRequest();
		$router = new PageRouter();
		$router->setApplication($application);
		$request->setRouter($router);

		// Generate a test journal.
		$journal = new Journal();

		// Test indexing. The service returns the number of documents that
		// were successfully processed.
		$journal->setId('1');
		$journal->setPath('test');
		self::assertGreaterThan(0, $this->solrWebService->indexJournal($journal));
		$journal->setId('2');
		$journal->setPath('lucene-test');
		self::assertGreaterThan(1, $this->solrWebService->indexJournal($journal));
	}
}
?>