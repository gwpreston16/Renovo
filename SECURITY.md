# Security policy

## Supported versions

Security fixes go into the **latest minor release** of Renovo. If you run an
older one, the fix is to upgrade; the changelog and
[`docs/upgrading.md`](docs/upgrading.md) say what each release changes and what
to back up first.

## Reporting a vulnerability

Please report it **privately**, not in a public issue or discussion.

Use GitHub's private vulnerability reporting: on the repository's **Security**
tab, choose **Report a vulnerability**. That opens a private thread with the
maintainer, and nobody else can see it.

Say what you found, how to reproduce it, which version you tested and what you
think it allows. A proof of concept helps; a working exploit against someone
else's instance does not, and please do not test against one.

## What to expect

This is a small, self-hosted project looked after by one maintainer, so there is
no promised response time. A report is read, acknowledged when it has been, and
fixed in a release when it is confirmed. You will be told when that release is
out and, unless you would rather not be named, credited in the changelog.

## What is in scope

- The application: the code in this repository, as it runs.
- The Docker images built from it, and the compose files it ships.
- The default configuration, and what the documentation tells an operator to
  do.

Out of scope: a vulnerability in a dependency that does not affect Renovo (it
is tracked by the dependency audit in CI and Dependabot), a deployment that has
been configured against the documentation, and social engineering of a user or
an operator.
