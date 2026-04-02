# Before Public

- Login page polish:
  In the block that shows `$ doki create app team-dashboard`, add a small italic `(not yet implemented)` note somewhere visually nice inside that same block so users do not assume the command already works.

- Studio module split:
  Create a dedicated `studio` module and move App Studio into it.

- Studio workflows page:
  Add a new `Workflows` page inside the new `studio` module, so App Studio and workflow creation live together under Studio.

- Workflow Studio:
  Implement a workflow-building studio with both manual creation and AI-assisted creation, similar to how app creation works today.

- AI workflow creation:
  Let users create workflows by asking AI in plain language.

- Workflow context integration:
  Use the workflows context settings that already exist as the configuration/context source for AI workflow generation.
