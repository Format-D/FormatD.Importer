
# FormatD.Importer

This package provides an XML-Importer for Neos Flow entities.


## What does it do?

This package provides an XML-Importer for Neos Flow entities. By creating an XML following a specific structure you can for example create fixtures for tests or use it to import data and resources into the system for which you do not have a frontend for editing data.
You can also import the entities only into memory and choose not to persist them (useful for test fixtures for example).

## Running the import

Importing a simple file as one-time-import:
```
    ./flow import:xml /path/to/xml-file.xml
```

### Import and updates

If you would like to later on update data you have previously imported you can use an optional parameter to inject the UUIDs into the imported XMLs.
That way if you change it and import it again the entities are updated and not created again.
```
    ./flow import:xml /path/to/xml-file.xml --enableXmlModification
```

It is possible to reference other entities in the xml by specifying a id. If you have already imported stuff you can load this data into memory so that all references are loaded. 
```
    ./flow import:xml /path/to/xml-file.xml --initializePathAndFilename="/path/to/other/files/with"
```

### Using importer for test fixtures

The importer can be used as a convenient way to create fixtures for functional or end-to-end testing.

If you need some data in a functional test you can do something like this:

```
   
    $importService->setPersistenceEnabled(FALSE);
    $importService->importFromFile('/path/to/file');
    $fixture = $importService->getImportedObjectByReference(My\Package\Domain\Model\ShippingRate:class, 'standard');
    
```


## XML Structure for import files

### Basic Example

Here is an example of a simple import-file. This creates two shippingRate entities and two timeFrame entities referencing one of the shipping rates.

```xml
<?xml version="1.0" encoding="UTF-8"?>
<data>
	<meta>
		<repository type="My\Package\Domain\Model\ShippingRate" repositoryName="My\Package\Domain\Repository\ShippingRateRepository" />
		<repository type="My\Package\Domain\Model\TimeFrame" repositoryName="My\Package\Domain\Repository\TimeFrameRepository" />
		<repository type="Neos\Flow\Security\Account" repositoryName="Neos\Flow\Security\AccountRepository" />
	</meta>
	<content>
	
	    <!--  Arbitrary named nodes. The inner nodes are iterated and need a type property -->
	    <shippingRates>
	        <!-- specify an optional id=".." if you want to reference the imported entity later on -->
			<shippingRate id="standard" type="My\Package\Domain\Model\ShippingRate">
				<properties>
					<name>Standard</name>
					<level type="integer">3</level>
					<cost type="double">3.90</cost>
				</properties>
			</shippingRate>
			<shippingRate id="stundengenau" type="My\Package\Domain\Model\ShippingRate">
				<properties>
					<name>Stundengenau</name>
					<level type="integer">4</level>
					<cost type="double">5.90</cost>
				</properties>
			</shippingRate>
		</shippingRates>

	    <timeFrames>
			<timeFrame id="8-9" type="My\Package\Domain\Model\TimeFrame">
				<properties>
					<name>8 - 9 Uhr</name>
					<startTime type="DateTime">
						<constructorArguments>
							<arg1>08:00:00</arg1>
						</constructorArguments>
					</startTime>
					<duration type="integer">1</duration>
					<defaultCapacity type="integer">5</defaultCapacity>
					<defaultShippingRate reference="stundengenau" type="My\Package\Domain\Model\ShippingRate" />
				</properties>
			</timeFrame>
			<timeFrame id="9-10" type=">My\Package\Domain\Model\TimeFrame">
				<properties>
					<name>9 - 10 Uhr</name>
					<startTime type="DateTime">
						<constructorArguments>
							<arg1>09:00:00</arg1>
						</constructorArguments>
					</startTime>
					<duration type="integer">1</duration>
					<defaultCapacity type="integer">5</defaultCapacity>
					<defaultShippingRate reference="stundengenau" type="My\Package\Domain\Model\ShippingRate" />
				</properties>
			</timeFrame>
		</timeFrames>
		
	</content>
</data>
```

The section `<meta>` contains only a mapping what repository is used for which entity. The `<content>` section contains arbitrary named sections such as `<shippingRates>` in our example.
These Sections themselves contain multiple sections with a `type=""` attribute.

It is possible to split the imported data into multiple files prefixed by a 3 digit number (be aware that the files are sorted before import). You have to take care that referenced entities are already imported before referencing them.

```
    000_MetaData.xml    
    010_ShippingRates.xml
    020_TimeFrames.xml
``` 

### Nesting and Relations

It is possible to define relations (ManyToMany or OneToMany) in a seperate section `<relations>`:

```xml
...
        <rule type="My\Package\Domain\Model\Rule">
            <properties>
                <active type="boolean">TRUE</active>
                <name>Some rule to manage the TimeFrame 8-9</name>
                <priority type="integer">100</priority>
                <capacity type="integer">50</capacity>
                <shippingRate reference="stundengenau" type="My\Package\Domain\Model\ShippingRate" />
            </properties>
            <relations>
                <zone reference="some-alrerady-imported-zone" type="My\Package\Domain\Model\Zone"  />
                <timeFrame reference="8-9" type="My\Package\Domain\Model\TimeFrame" />
                <timeFrame reference="9-10" type="My\Package\Domain\Model\TimeFrame" />
            </relations>
        </rule>
...
```

The importer works recursively and also imports nested structures:

```xml
...
        <rule type="My\Package\Domain\Model\Rule">
            <properties>
                ...
            </properties>
            <relations>
                <zone reference="some-alrerady-imported-zone" type="My\Package\Domain\Model\Zone"  />
                <timeFrame reference="8-9" type="My\Package\Domain\Model\TimeFrame" />
                <timeFrame reference="9-10" type="My\Package\Domain\Model\TimeFrame" />
                <!-- this would also be possible: -->
                <timeFrame id="10-11" type=">My\Package\Domain\Model\TimeFrame">
                    <properties>
                        <name>10 - 11 Uhr</name>
                        <startTime type="DateTime">
                            <constructorArguments>
                                <arg1>10:00:00</arg1>
                            </constructorArguments>
                        </startTime>
                        <duration type="integer">1</duration>
                        <defaultCapacity type="integer">5</defaultCapacity>
                        <defaultShippingRate type="My\Package\Domain\Model\ShippingRate" >
                            <properties>
                                <name>Some other Rate</name>
                                <level type="integer">6</level>
                                <cost type="double">19.90</cost>
                            </properties>
                        </defaultShippingRate>
                    </properties>
                </timeFrame>
            </relations>
        </rule>
...
```