<!--
<div align="left">
  <img width="600" title="Craft Elastica" src="https://github.fork.de/Craft_HeRe_201223.svg">
</div>
-->

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

- Craft CMS >= 3.5.x

## Setup

**1. Install**

Install the package

```sh
cd /path/to/project
composer require fork/craft-elastica
```

**2. Configuration**

Go to the plugin settings page and enter a index prefix name which is prepended to the indexes beeing created in Elasticsearch.
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
   }
);
// build elasticsearch index data
Event::on(
   Indexer::class,
   Indexer::EVENT_BEFORE_INDEX_DATA,
   function (IndexEvent $event) {
       // build your custom data structure to index
       $indexData = MyCustomPlugin::$plugin->mySearchService->getIndexData($event->entry);
       $event->indexData = $indexData;
   }
);
```

## Roadmap

- [ ] Logo
- [ ] Index categories
- [ ] Maybe include search proxy
- [ ] More documentation

---

<div align="center">
  <img src="https://github.fork.de/heart.png" width="38" height="41" alt="Fork Logo" />

  <p>Brought to you by <a href="https://www.fork.de">Fork Unstable Media GmbH</a></p>
</div>
