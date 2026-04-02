import { test, expect } from '@playwright/test';
import fs from 'fs';
import path from 'path';
import dotenv from 'dotenv';

// Load environment variables
dotenv.config();

// Generate timestamped folder name
const timestamp = new Date().toISOString().replace(/[:.]/g, '-');
const resultsDir = path.resolve('./playwright-results');
const screenshotDir = path.join(resultsDir, `test-${timestamp}`);

// Ensure the folder exists before tests run
test.beforeAll(async () => {
  if (!fs.existsSync(resultsDir)) {
    fs.mkdirSync(resultsDir, { recursive: true });
  }
  if (!fs.existsSync(screenshotDir)) {
    fs.mkdirSync(screenshotDir, { recursive: true });
  }
});

// Enable video recording for this test
test.use({
  viewport: {
    height: 900,
    width: 1440
  },
  video: {
    mode: 'on',
    size: { width: 1440, height: 900 }
  }
});

test('Test Pharmalia', async ({ page }) => {
  test.setTimeout(120000);
  
  const TEST_EMAIL = process.env.TEST_EMAIL || '';
  const TEST_PASSWORD = process.env.TEST_PASSWORD || '';
  const TEST_SECURITY_CODE = process.env.TEST_SECURITY_CODE || '';
  const TEST_MEMBER_PASSWORD = process.env.TEST_MEMBER_PASSWORD || '';
  const TEST_MEMBER_NAME = process.env.TEST_MEMBER_NAME || '';
  
  await test.step('Connexion à la plateforme SSO', async () => {
    await page.goto('https://sso.ocp-pharmalia.fr/ocp-auth-pharmacien-app/login');
    await page.locator('.sc-dAlyuH').click();
    await page.getByTestId('uc-accept-all-button').click();
  });

  await test.step('Saisie des identifiants de connexion', async () => {
    await page.getByRole('textbox', { name: 'Email de la pharmacie*' }).click();
    await page.getByRole('textbox', { name: 'Email de la pharmacie*' }).fill(TEST_EMAIL);
    await page.getByRole('textbox', { name: 'Mot de passe*' }).click();
    await page.getByRole('textbox', { name: 'Mot de passe*' }).fill(TEST_PASSWORD);
    await page.getByRole('button', { name: 'Continuer' }).click();
  });

  await test.step('Saisie du code de sécurité à 8 chiffres', async () => {
    await page.getByRole('textbox', { name: 'Code de sécurité' }).click();
    await page.getByRole('textbox', { name: 'Code de sécurité' }).fill(TEST_SECURITY_CODE);
    await page.locator('div').filter({ hasText: '1' }).nth(4).click();
    await page.waitForTimeout(500);
    await page.locator('div').filter({ hasText: '1' }).nth(4).click();
    await page.waitForTimeout(500);
    await page.locator('div').filter({ hasText: '1' }).nth(4).click();
    await page.waitForTimeout(500);
    await page.locator('div').filter({ hasText: '1' }).nth(4).click();
    await page.waitForTimeout(500);
    await page.getByRole('button', { name: 'Suivant' }).click();
  });

  await test.step('Vérification de la page d\'accueil du portail', async () => {
    await page.goto('https://www.ocp-pharmalia.fr/ocp-pharmacien/');
    await page.waitForTimeout(7000);
    await expect(page.locator('#homepageTitreSearch')).toContainText('Que recherchez-vous ?');
    await expect(page.locator('#homepageDashboardContent')).toContainText('Vos essentiels en 1 clic');
    await expect(page.locator('#slideshowElement')).toContainText('Les offres à ne pas manquer');
    await expect(page.locator('#homepageTitreActu')).toContainText('À la une');
    await expect(page.locator('#slideshowElementActu')).toContainText('Actualités produits');
  });

  await test.step('Recherche d\'un produit (Doliprane)', async () => {
    await page.getByRole('textbox', { name: 'Rechercher un produit' }).click();
    await page.getByRole('textbox', { name: 'Rechercher un produit' }).fill('doliprane');
    await page.getByRole('textbox', { name: 'Rechercher un produit' }).press('Enter');
    await page.waitForTimeout(5000);
    await expect(page.getByRole('heading')).toContainText('RECHERCHE: "doliprane"');
  });

  await test.step('Ajout d\'un produit au panier (quantité: 5)', async () => {
    await page.locator('#marketplace-result-quantite-0').click();
    await page.locator('#marketplace-result-quantite-0').fill('5');
    await page.locator('#marketplace-result-quantite-0').press('Enter');
    await page.waitForTimeout(5000);
    await page.locator('#marketplace-recherche-ajout-panier-0').click();
    await page.waitForTimeout(6000);
    await expect(page.locator('#notif-panier')).toContainText('1');
  });

  await test.step('Vérification du panier et passage de commande', async () => {
    await page.getByRole('link', { name: 'Mon panier' }).click();
    await page.waitForTimeout(5000);
    await expect(page.getByRole('spinbutton')).toHaveValue('5');
    await expect(page.getByRole('button')).toContainText('Passer la commande');
    await page.getByRole('button', { name: 'Passer la commande' }).click();
    await page.getByText(TEST_MEMBER_NAME).click();
    await expect(page.locator('#content-vue')).toContainText('Merci pour votre commande.');
    await expect(page.locator('tbody')).toContainText('5');
  });

  await test.step('Vérification du suivi des commandes', async () => {
    await page.getByRole('link', { name: 'suivi des commandes' }).click();
    await expect(page.locator('tbody')).toContainText('Répartition');
  });

  await test.step('Navigation vers AHDS - Mon équipe', async () => {
    await page.goto('https://www.ahds.ocp-pharmalia.fr/ocp-pharmacien/mon-equipe/accueil');
    await page.waitForTimeout(7000);
    await page.getByText(TEST_MEMBER_NAME).click();
    await page.getByRole('textbox', { name: 'Mot de passe*' }).click();
    await page.getByRole('textbox', { name: 'Mot de passe*' }).fill(TEST_MEMBER_PASSWORD);
    await page.getByRole('button', { name: 'Valider' }).click();
  });

  await test.step('Vérification du tableau de bord Mon équipe', async () => {
    await page.waitForTimeout(7000);
    await expect(page.locator('h2')).toContainText('Fil d\'actualité');
    await expect(page.locator('#ajouter_membre')).toContainText('Ajouter un membre');
    await expect(page.locator('#content-vue')).toContainText('Accueil');
    await expect(page.locator('#content-vue')).toContainText('Cahier de liaison');
    await expect(page.locator('#content-vue')).toContainText('Liste des actions');
    await expect(page.locator('#content-vue')).toContainText('Agenda');
    await expect(page.locator('#content-vue')).toContainText('Planning d\'équipe');
    await expect(page.locator('#content-vue')).toContainText('Gestion des ressources');
  });

  await test.step('Navigation et vérification de Mes Patients', async () => {
    await page.goto('https://www.ahds.ocp-pharmalia.fr/ocp-pharmacien/mes-patients');
    await page.waitForTimeout(7000);
    await expect(page.locator('#mesPatientsCreate')).toContainText('Créer une fiche patient');
    await expect(page.locator('#content-vue')).toContainText('Mes Patients');
    await expect(page.locator('#chroniques-tab')).toContainText('Délivrances chroniques');
    await expect(page.locator('#content-vue')).toContainText('Application Mobile');
    await expect(page.locator('#content-vue')).toContainText('Entretiens');
    await expect(page.locator('#mesPatientTabNouveauxPatients')).toContainText('Voir la fiche');
    await expect(page.locator('#search').getByRole('textbox', { name: 'Rechercher un patient' })).toBeEmpty();
    await expect(page.locator('#mesPatientDatesFilter_Btn')).toContainText('Filtrer par date');
  });

  await test.step('Vérification de Délivrances chroniques - Accueil', async () => {
    await page.waitForTimeout(5000);
    await page.getByRole('link', { name: 'Délivrances chroniques' }).click();
    await expect(page.locator('#container-chronique')).toContainText('Accueil');
    await expect(page.locator('#container-chronique')).toContainText('Ajouter une ordonnance chronique');
    await expect(page.locator('#container-chronique')).toContainText('Voir mes ordonnances chroniques');
    await expect(page.locator('#container-chronique')).toContainText('Accéder à l\'historique des commandes');
  });

  await test.step('Consultation des ordonnances chroniques existantes', async () => {
    await page.waitForTimeout(3000);
    await page.getByRole('link', { name: 'Voir mes ordonnances chroniques', exact: true }).click();
    await page.waitForTimeout(5000);
    await expect(page.locator('#search-patient-input')).toBeEmpty();
    await expect(page.locator('#container-chronique')).toContainText('Suivi des ordonnances chroniques');
  });

  await test.step('Navigation vers création d\'ordonnance chronique', async () => {
    await page.getByRole('link', { name: 'Ajouter une ordonnance' }).click();
    await page.waitForTimeout(5000);
    await expect(page.locator('#container-chronique')).toContainText('> Créer un nouveau patient');
    await expect(page.locator('#container-chronique')).toContainText('Sélectionner un patient');
  });

  // Move the video to the screenshotDir after the test
  const videoPath = await page.video()?.path();
  if (videoPath) {
    const destPath = path.join(screenshotDir, 'test-video.webm');
    fs.copyFileSync(videoPath, destPath);
    // Optionally, delete the original video file
    await page.close();
  }
});