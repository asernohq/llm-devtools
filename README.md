# LLM DevTools

Small, pragmatic developer tools for LLM-assisted coding workflows.

This repository contains tools for extracting compact source context, preparing prompts,
and reducing hallucination risk when working with large language models on real codebases.

## Why this exists

LLMs are most useful when they receive the right context.

They are also most dangerous when they receive almost the right context.

This repository exists to make it easier to give coding assistants precise, compact, and
project-aware source context without dumping an entire codebase into a prompt.

The goal is not to replace the developer. The goal is to make the developer faster while
keeping the boring-but-important details visible.

## Status

Early utility repository.

The tools are intentionally practical and lightweight. APIs, names, and file layout may
change while the toolkit is still young.

## Requirements

Requirements may vary by tool.

Typical requirements:

- A recent PHP CLI runtime.
- Local source code checkouts.
- A terminal or shell suitable for developer workflows.

Tools in this repository are primarily designed for local development workflows.

## Installation

Clone the repository:

```bash
git clone https://github.com/asernohq/llm-devtools.git
cd llm-devtools
````

Use the relevant tool directly from the repository, or follow the usage notes in the tool
itself.

## Suggested local layout

The tools work best when source repositories live in predictable local structures.

Example:

```text
C:\dev\www\vendor-or-org\project-a
C:\dev\www\vendor-or-org\project-b
C:\dev\www\vendor-or-org\project-c
```

Deterministic local paths make it easier to extract focused context without scanning
unrelated projects.

## Usage

Each tool should provide its own help output or usage notes.

General pattern:

```bash
php path/to/tool.php --help
```

Many tools are expected to print Markdown or plain text that can be pasted directly into
LLM chats, coding agents, issue descriptions, or documentation drafts.

On Windows, output can often be copied directly to the clipboard:

```bat
php path\to\tool.php [options] | clip
```

## Output format

Tools should prefer simple, structured output.

Good output formats include:

* Markdown.
* Plain text.
* JSON, when intended for other tools.
* Line-oriented output for shell workflows.

When output is intended for LLM prompts, it should be compact, explicit, and easy to paste.

Example structure:

````markdown
# Context

## path/to/source-file.php

```php
<?php
declare(strict_types=1);

// Source code...
```
````

## Design principles

### Read-only by default

Tools in this repository should be safe by default.

A tool should not modify source files, write generated code into projects, run migrations,
execute application bootstraps, or call remote services unless that behavior is explicit,
documented, and opt-in.

### Low overhead

Tools should be fast enough to use repeatedly while coding.

Avoid unnecessary bootstrapping, package discovery magic, or expensive scanning when simple
deterministic file lookups are enough.

### Deterministic behavior

The same input should produce the same output.

When a tool cannot find something, it should fail clearly instead of guessing.

### LLM-friendly output

Output should be compact, structured, and copy/paste-friendly.

The goal is to help the LLM understand the actual codebase shape without wasting tokens on
irrelevant files.

### Human-readable first

These tools are for developers, not only agents.

CLI flags, errors, and output should be understandable without reading the source.

### Small tools over platforms

Prefer focused tools that solve one annoying workflow well.

Avoid turning the repository into a framework unless repeated real-world use proves that a
shared structure is needed.

## Repository structure

The repository may contain standalone scripts, small utilities, documentation helpers, or
packaged command-line tools.

A simple structure is preferred until there is a concrete need for something heavier:

```text
llm-devtools/
  README.md
  LICENSE
  tools/
    ...
```

A more structured layout may be introduced later if it provides clear value:

```text
llm-devtools/
  bin/
    ...
  src/
    ...
  tools/
    ...
  tests/
    ...
  composer.json
  README.md
  LICENSE
```

## Naming conventions

Tool names should be specific and boring.

Good tool names describe what they do. Avoid names that are clever but unclear. The LLM
already has enough ambiguity to work with.

## Safety notes

Before pasting output into an external LLM or hosted coding assistant, review the context.

Source files may contain:

* Internal architecture details.
* Private package names.
* Business logic.
* Comments with sensitive hints.
* Configuration paths.
* Test data.

Do not include secrets, credentials, production dumps, private keys, tokens, or customer data
in LLM prompts.

## Roadmap ideas

Possible future directions:

* Source context extraction.
* Prompt bundle generation.
* Repository map generation.
* Architecture summary generation.
* Test context extraction.
* Static checks for LLM-generated patches.
* Diff-to-prompt helpers.
* Coding-agent instruction file generators.
* Compact symbol index generation.
* Context packs for selected project areas.

Nothing on this list is promised. Tools should be added when they remove real friction.

## Contributing

Contributions are welcome when they keep the repository practical, small, and deterministic.

Preferred contribution style:

* Keep tools focused.
* Avoid heavy dependencies unless they provide clear value.
* Prefer explicit CLI options over hidden behavior.
* Keep output stable and easy to diff.
* Add examples when adding new behavior.
* Do not introduce source-modifying behavior without making it explicit and opt-in.

## License

This repository is released under the MIT License.

See [LICENSE](LICENSE) for details.

## Copyright

Copyright (c) Aserno ApS.
