<div align="left">
  <img width="600" title="Craft Elastica" src="resources/img/plugin-logo.png" alt="Fork Logo" />
</div>

# Elastica Plugin for Craft 5.x

**Table of contents**

- [Features](#features)
- [Requirements](#requirements)
- [Setup](#setup)
- [Usage](#usage)
- [Roadmap](#roadmap)

<!-- /TOC -->

---

## Features

- Index entries and other elements in Elasticsearch
- Customize Index data structures via hooks  
- Define Index Templates to configure the Index and its fields and mapping in Elasticsearch
- Re-Index contents via utility or console command

## Requirements

- Craft CMS >= 5.x

### Version Matrix

| Elastica Version | Craft Version | ElasticSearch Version |
|------------------|---------------|-----------------------|
| 4.x              | 5.x           | 8.x                   |
| 3.x              | 5.x           | 7.x                   |
| 2.x              | 4.x           | 7.x                   |

## Setup

**1. Install**

Install the package

```sh
cd /path/to/project
composer require fork/craft-elastica
```

**2. Configuration**

Go to the plugin settings page and enter a index prefix name which is prepended to the indexes being created in Elasticsearch.
The name of the index is generated from this prefix.
Also provide the hostname of the elasticsearch instance.

## Usage

To generate the data to index register event handlers in your own module or plugin class like this:

```
// initialize elasticsearch indexer
Event::on(
   Indexer::class,
   Indexer::EVENT_INDEXER_INIT,
   function (IndexerInitEvent $event) {
       $event->addSectionHandles([
           'articles',
       ]);
       $event->addCategoryGroupHandles([
           'topics',
       ]);
       $event->addVolumeHandles([
           'images',
       ]);
   }
);
// build elasticsearch index data
Event::on(
   Indexer::class,
   Indexer::EVENT_BEFORE_INDEX_DATA,
   function (IndexEvent $event) {
       // build your custom data structure to index
       $indexData = MyCustomPlugin::$plugin->mySearchService->getIndexData($event->sender);
       $event->indexData = $indexData;
   }
);
```

## Roadmap

- [x] Logo
- [x] Index categories
- [ ] Maybe include search proxy
- [ ] Exclude sites via settings
- [ ] Show index info / test index in utility
- [ ] More documentation

---

<div align="center">
  <img src="resources/img/heart.png" width="38" height="41" alt="Made with love by Fork" />

  <p>Brought to you by <a href="https://www.fork.de">Fork Unstable Media GmbH</a></p>
</div>
