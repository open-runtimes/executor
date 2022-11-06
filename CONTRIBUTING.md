# Contributing

We would ❤️ for you to contribute to Open Runtimes and help make it better! We want contributing to Open Runtmies to be fun, enjoyable, and educational for anyone and everyone. All contributions are welcome, including issues, new docs as well as updates and tweaks, blog posts, workshops, and more.

## How to Start?

If you are worried or don’t know where to start, check out our next section explaining what kind of help we could use and where can you get involved. You can reach out with questions to [Eldad Fux (@eldadfux)](https://twitter.com/eldadfux) or anyone from the [Open Runtimes team on Discord](https://discord.gg/mkZcevnxuf). You can also submit an issue, and a maintainer can guide you!

## Code of Conduct

Help us keep Open Runtimes open and inclusive. Please read and follow our [Code of Conduct](/CODE_OF_CONDUCT.md).

## Submit a Pull Request 🚀

Branch naming convention is as following

`TYPE-ISSUE_ID-DESCRIPTION`

example:

```
doc-548-submit-a-pull-request-section-to-contribution-guide
```

When `TYPE` can be:

- **feat** - is a new feature
- **doc** - documentation only changes
- **cicd** - changes related to CI/CD system
- **fix** - a bug fix
- **refactor** - code change that neither fixes a bug nor adds a feature

**All PRs must include a commit message with the changes description!**

For the initial start, fork the project and use git clone command to download the repository to your computer. A standard procedure for working on an issue would be to:

1. `git pull`, before creating a new branch, pull the changes from upstream. Your master needs to be up to date.

```
$ git pull
```

2. Create new branch from `master` like: `doc-548-submit-a-pull-request-section-to-contribution-guide`<br/>

```
$ git checkout -b [name_of_your_new_branch]
```

3. Work - commit - repeat ( be sure to be in your branch )

4. Push changes to GitHub

```
$ git push origin [name_of_your_new_branch]
```

5. Submit your changes for review
   If you go to your repository on GitHub, you'll see a `Compare & pull request` button. Click on that button.
6. Start a Pull Request
   Now submit the pull request and click on `Create pull request`.
7. Get a code review approval/reject
8. After approval, merge your PR
9. GitHub will automatically delete the branch after the merge is done. (they can still be restored).

## Running

To run Open Runtimes Executor, make sure to install PHP dependencies:

```bash
docker run --rm --interactive --tty --volume $PWD:/app composer composer install --profile --ignore-platform-reqs
```

Next start the Docker Compose stack that includes executor server with nessessary networks and volumes:

```bash
docker compose up -d
```

You can now use `http://localhost:9800/v1/` endpoint to communicate with Open Runtimes Executor. You can see 'Getting Started' section of README to learn about endpoints.

## Testing

We use PHP framework PHPUnit to test Open Runtimes. Every PR is automatically tested by Travis CI, and tests run for all runtimes. Since this is PHP source code, we also run [Pint](https://github.com/laravel/pint) linter and [PHPStan](https://phpstan.org/) code analysis.

Before running the tests, make sure to install all required PHP libraries:

```bash
composer install --profile --ignore-platform-reqs
```

> We run tests in separate Swoole container to ensure unit tests have all nessessary extensions ready.

Once ready, you can test executor.

To run tests, you need to start Docker Compose stack, and then run PHPUnit:

```bash
docker compose up -d
# Wait for ~5 seconds for executor to start
docker run --rm -v $PWD:/app --network openruntimes-runtimes -w /app phpswoole/swoole:4.8.12-php8.0-alpine sh -c \ "composer test"
```

To run linter, you need to run Pint:

```bash
composer format
```

To run static code analysis, you need to run PHPStan:

```bash
composer check
```

## Introducing New Features

We would 💖 you to contribute to Open Runtimes, but we would also like to make sure Open Runtimes is as great as possible and loyal to its vision and mission statement 🙏.

For us to find the right balance, please open an issue explaining your ideas before introducing a new pull request.

This will allow the Open Runtimes community to have sufficient discussion about the new feature value and how it fits in the product roadmap and vision.

This is also important for the Open Runtimes lead developers to be able to give technical input and different emphasis regarding the feature design and architecture. Some bigger features might need to go through our [RFC process](https://github.com/appwrite/rfc).

## Other Ways to Help

Pull requests are great, but there are many other areas where you can help Open Runtimes.

### Blogging & Speaking

Blogging, speaking about, or creating tutorials about one of Open Runtimes many features is great way to contribute and help our project grow.

### Presenting at Meetups

Presenting at meetups and conferences about your Open Runtimes projects. Your unique challenges and successes in building things with Open Runtimes can provide great speaking material. We’d love to review your talk abstract/CFP, so get in touch with us if you’d like some help!

### Sending Feedbacks & Reporting Bugs

Sending feedback is a great way for us to understand your different use cases of Open Runtimes better. If you had any issues, bugs, or want to share about your experience, feel free to do so on our GitHub issues page or at our [Discord channel](https://discord.gg/mkZcevnxuf).

### Submitting New Ideas

If you think Open Runtimes could use a new feature, please open an issue on our GitHub repository, stating as much information as you can think about your new idea and it's implications. We would also use this issue to gather more information, get more feedback from the community, and have a proper discussion about the new feature.

### Improving Documentation

Submitting documentation updates, enhancements, designs, or bug fixes. Spelling or grammar fixes will be very much appreciated.

### Helping Someone

Searching for Open Runtimes, GitHub or StackOverflow and helping someone else who needs help. You can also help by teaching others how to contribute to Open Runtimes repo!