<?php
class SolrIndexTest extends SapphireTest {

	function setUpOnce() {
		parent::setUpOnce();

		Phockito::include_hamcrest();
	}
	
	function testBoost() {
		$index = new SolrIndexTest_FakeIndex();
		
		$query = new SearchQuery();
		$query->search(
			'term', 
			null, 
			array('Field1' => 1.5, 'HasOneObject_Field1' => 3)
		);
		$params = $index->getQueryParams($query);
		$this->assertEquals(
			'+(Field1:term^1.5 OR HasOneObject_Field1:term^3)',
			$params['q']
		);
	}

	function testBooleanQueries() {
		$index = new SolrIndexTest_FakeIndex();
		
		$query = new SearchQuery();
		$query->search('term1 AND term2');
		$params = $index->getQueryParams($query);
		$this->assertEquals(
			'term1 AND term2',
			$params['q']
		);

		$query = new SearchQuery();
		$query->search('term1 and term2');
		$params = $index->getQueryParams($query);
		$this->assertEquals(
			'+term1 +and +term2',
			$params['q']
		);

		$query = new SearchQuery();
		$query->search('term1  AND  term2 NOT term3');
		$params = $index->getQueryParams($query);
		$this->assertEquals(
			'term1 AND term2 NOT term3',
			$params['q']
		);

		$query = new SearchQuery();
		$query->search('term1 -term2');
		$params = $index->getQueryParams($query);
		$this->assertEquals(
			'+term1 -term2',
			$params['q']
		);
	}

	function testPhraseQueries() {
		$index = new SolrIndexTest_FakeIndex();

		$query = new SearchQuery();
		$query->search('"term1 term2" term3');
		$params = $index->getQueryParams($query);
		$this->assertEquals(
			'+"term1 term2" +term3',
			$params['q']
		);
	}

	function testIndexExcludesNullValues() {
		$serviceSpy = $this->getServiceSpy();
		$index = new SolrIndexTest_FakeIndex();
		$index->setService($serviceSpy);		
		$obj = new SearchUpdaterTest_Container();

		$obj->Field1 = 'Field1 val';
		$obj->Field2 = null;
		$obj->MyDate = null;
		$docs = $index->add($obj);
		$value = $docs[0]->getField('SearchUpdaterTest_Container_Field1');
		$this->assertEquals('Field1 val', $value['value'], 'Writes non-NULL string fields');
		$value = $docs[0]->getField('SearchUpdaterTest_Container_Field2');
		$this->assertFalse($value, 'Ignores string fields if they are NULL');
		$value = $docs[0]->getField('SearchUpdaterTest_Container_MyDate');
		$this->assertFalse($value, 'Ignores date fields if they are NULL');

		$obj->MyDate = '2010-12-30';
		$docs = $index->add($obj);
		$value = $docs[0]->getField('SearchUpdaterTest_Container_MyDate');
		$this->assertEquals('2010-12-30T00:00:00Z', $value['value'], 'Writes non-NULL dates');
	}

	function testAddFieldExtraOptions() {
		$origMode = Director::get_environment_type();
		Director::set_environment_type('live'); // dev mode would for stored=true for everything
		$index = new SolrIndexTest_FakeIndex();

		$defs = simplexml_load_string('<fields>' . $index->getFieldDefinitions() . '</fields>');
		$defField1 = $defs->xpath('field[@name="SearchUpdaterTest_Container_Field1"]');
		$this->assertEquals((string)$defField1[0]['stored'], 'false');

		$index->addFilterField('Field1', null, array('stored' => 'true'));
		$defs = simplexml_load_string('<fields>' . $index->getFieldDefinitions() . '</fields>');
		$defField1 = $defs->xpath('field[@name="SearchUpdaterTest_Container_Field1"]');
		$this->assertEquals((string)$defField1[0]['stored'], 'true');

		Director::set_environment_type($origMode);
	}

	function testAddAnalyzer() {
		$index = new SolrIndexTest_FakeIndex();

		$defs = simplexml_load_string('<fields>' . $index->getFieldDefinitions() . '</fields>');
		$defField1 = $defs->xpath('field[@name="SearchUpdaterTest_Container_Field1"]');
		$analyzers = $defField1[0]->analyzer;
		$this->assertFalse((bool)$analyzers);

		$index->addAnalyzer('Field1', 'charFilter', array('class' => 'solr.HTMLStripCharFilterFactory'));
		$defs = simplexml_load_string('<fields>' . $index->getFieldDefinitions() . '</fields>');
		$defField1 = $defs->xpath('field[@name="SearchUpdaterTest_Container_Field1"]');
		$analyzers = $defField1[0]->analyzer;
		$this->assertTrue((bool)$analyzers);
		$this->assertEquals('solr.HTMLStripCharFilterFactory', $analyzers[0]->charFilter[0]['class']);
	}

	function testAddCopyField() {
		$index = new SolrIndexTest_FakeIndex();		
		$index->addCopyField('sourceField', 'destField');
		$this->assertContains(
			'<copyField source=\'sourceField\' dest=\'destField\' />',
			$index->getCopyFieldDefinitions()
		);
	}

	protected function getServiceSpy() {
		$serviceSpy = Phockito::spy('SolrService');
		$fakeResponse = new Apache_Solr_Response(new Apache_Solr_HttpTransport_Response(null, null, null));

		Phockito::when($serviceSpy)
			->_sendRawPost(anything(), anything(), anything(), anything())
			->return($fakeResponse);

		Phockito::when($serviceSpy)
			->_sendRawGet(anything())
			->return($fakeResponse);

		return $serviceSpy;
	}

}

class SolrIndexTest_FakeIndex extends SolrIndex {
	function init() {
		$this->addClass('SearchUpdaterTest_Container');

		$this->addFilterField('Field1');
		$this->addFilterField('MyDate', 'Date');
		$this->addFilterField('HasOneObject.Field1');
		$this->addFilterField('HasManyObjects.Field1');
	}
}