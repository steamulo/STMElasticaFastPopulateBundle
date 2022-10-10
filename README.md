# Elastica Fast Populate Bundle

Improves performance of `fos:elastica:populate` command from [FOSElasticaBundle](https://github.com/FriendsOfSymfony/FOSElasticaBundle) by distributing the work among consumers.

This bundle is based on [Enqueue Elastica Bundle](https://github.com/php-enqueue/enqueue-elastica-bundle), and provides a command to directly manage the "populate" with a set of sub-processes automatically.
The performance gain depends on how much consumers you run.
For example 10 consumers may give you 5 to 7 times better performance.

## Installation

When installing the bundle, `Enqueue ElasticaBundle` is also automatically installed.
It must then be configured in order to indicate the way in which the sub-processes will communicate.

Default `enqueue.yaml`:
```
enqueue:
    default:
        transport:
            dsn: '%env(resolve:ENQUEUE_DSN)%'
        client: ~
enqueue_elastica:
    transport: '%enqueue.default_transport%'
    doctrine: ~
```

_**Note:** As long as you are on Symfony Flex you are done. If not, you have to do some extra things, like registering the bundle in your `AppKernel` class._

## Usage

* Run the populate command with some consumers (the more you run the better performance you might get):

```bash
php bin/console stm:fast-populate:populate --nb-subprocess=6
```


## Customization

### Options for the command

To limit the memory consumption of sub-processes you can use different parameters :

* `message-limit` - Integer. Consume n messages and exit.
* `time-limit` - Integer. Consume messages during this time.
* `memory-limit` - Integer. Consume messages until process reaches this memory limit in MB.

You can also use the classic populate options : [PopulateCommand.php](https://github.com/FriendsOfSymfony/FOSElasticaBundle/blob/master/src/Command/PopulateCommand.php)

For example, to limit the memory consumption to 800MB for 8 sub-processes and process 1000 elements per page, you can run the command like this:

```bash
php bin/console app:elastica:populate --memory-limit=800 --max-per-page=1000 --nb-subprocess=8
```

### Customizing the consumer

The `QueuePagerPersister` could be customized via options.
The options could be customized in a listener subscribed on `FOS\ElasticaBundle\Persister\Event\PrePersistEvent` event for example.

Here's the list of available options:

* `max_per_page` - Integer. Tells how many objects should be processed by a single worker at a time.
* `first_page` - Integer. Tells from what page to start rebuilding the index.
* `last_page` - Integer. Tells on what page to stop rebuilding the index.
* `populate_queue` - String. It is a name of a populate queue. Workers should consume messages from it.
* `populate_reply_queue` - String.  It is a name of a reply queue. The command should consume replies from it. Persister tries to create a temporary queue if not set.
* `reply_receive_timeout` - Float. A time a consumer waits for a message. In milliseconds.
* `limit_overall_reply_time` - Int. Limits an overtime allowed processing time. Throws an exception if it is exceeded.


## License

It is released under the [MIT License](LICENSE).
