# Features that would be good to have but not needed for v1

## Commands
- IO: being able to "plug" a command step with another via params (input) and returns (output)
- Curated list of templates: i think most users will have a specific stack and so wont need most templates.
- A more visual dependencies list between steps: something like aiflow but in ui (task1 >> task2...)
- doki cli: like aws the user would configure the command with key and secret and be able to execute commands like doki run \<commandId\> for example

## Apps
- Wrapping oss apps: being able to pull an app from github and wrapping it in a sandbox app, would need a new trust level something like quaranteened. The wrapped app would be forced to run in a container and with very strict security rules espacially when it comes to network and code execution permissions.
- PHP Allowed functions: Have all apps run in specific containers where the php config would prevent calling any system functions or any funcitons that app context doesnt allow

## Onboarding
- Activating modules: the first admin would choose to activate or not most big features (apps & commands, others may apply too)
- Strong and random password by default: generated for first admin, would require a command to be called inside the main container for reseting (maybe MVP/v1)

## Others
- Secret key rotation: forcing the user to change the key every x days would force good habits and avoid doki being a big vector for security threats
- External providers for secrey key
- SSO (Google, Azure AD, SAML etc)
- Stealth mode customization: being able to upload some html to be shown instead of the 404 page, the page would for example masquarade as a normal website. The js would stay the same.
- Renaming Doki: the app was at first very docker central/based and somewhat still is but it does so much that a more like "nom propre" name would fit better